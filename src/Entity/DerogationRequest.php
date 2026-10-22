<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\DerogationStatus;
use App\Repository\DerogationRequestRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Demande de dérogation à la structure fixée par le type de diplôme —
 * un responsable de formation décrit par texte ce qu'il voudrait ajouter/
 * déplacer/supprimer et qui n'est pas prévu (typiquement un ELP verrouillé,
 * cf. TreeNode::isLocked()) ; un admin SES traite la demande (il applique
 * lui-même la modification avec ses propres droits, puis marque le statut).
 * Aucun rejeu automatique de l'action : c'est un circuit de demande, pas un
 * moteur de permissions.
 */
#[ORM\Entity(repositoryClass: DerogationRequestRepository::class)]
#[ORM\Table(name: 'derogation_request')]
class DerogationRequest
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Formation::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Formation $formation;

    /** Nœud visé au moment de la demande (nid) — instantané, peut devenir obsolète si le nœud change ensuite. */
    #[ORM\Column(length: 32, nullable: true)]
    private ?string $nodeId = null;

    #[ORM\Column(length: 200, nullable: true)]
    private ?string $nodeLabel = null;

    /** Règle structurelle dérogée (clé stable d'une StructureViolation) ; null pour une demande libre. */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $ruleKey = null;

    /** Nœud portant l'écart ('' = racine d'une maquette mono-parcours) ; null pour une demande libre. */
    #[ORM\Column(length: 32, nullable: true)]
    private ?string $scopeNid = null;

    /** Valeur jusqu'à laquelle l'écart est toléré ; null = sans comparaison. */
    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $allowedValue = null;

    #[ORM\Column(type: Types::TEXT)]
    private string $message;

    #[ORM\Column(length: 20, enumType: DerogationStatus::class)]
    private DerogationStatus $status = DerogationStatus::Pending;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $adminNote = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $resolvedAt = null;

    public function __construct(Formation $formation, string $message, ?string $nodeId = null, ?string $nodeLabel = null)
    {
        $this->formation = $formation;
        $this->message = $message;
        $this->nodeId = $nodeId;
        $this->nodeLabel = $nodeLabel;
        $this->createdAt = new \DateTimeImmutable();
    }

    /** Rattache l'écart structuré demandé (règle, nœud, valeur accordée). */
    public function withDeviation(string $ruleKey, string $scopeNid, ?float $allowedValue): self
    {
        $this->ruleKey = $ruleKey;
        $this->scopeNid = $scopeNid;
        $this->allowedValue = $allowedValue;

        return $this;
    }

    public function getRuleKey(): ?string
    {
        return $this->ruleKey;
    }

    public function getScopeNid(): ?string
    {
        return $this->scopeNid;
    }

    public function getAllowedValue(): ?float
    {
        return $this->allowedValue;
    }

    public function isApproved(): bool
    {
        return DerogationStatus::Approved === $this->status;
    }

    /**
     * La dérogation tolère-t-elle cette valeur constatée ?
     * `:max` → actual <= accordé ; `:min` → actual >= accordé ;
     * toute autre clé → actual == accordé (ou sans comparaison si aucune valeur n'est accordée).
     */
    public function covers(float|int|null $actual): bool
    {
        if (null === $this->ruleKey) {
            return false;
        }
        if (null === $this->allowedValue) {
            return !str_ends_with($this->ruleKey, ':max') && !str_ends_with($this->ruleKey, ':min');
        }
        if (null === $actual) {
            return false;
        }

        return match (true) {
            str_ends_with($this->ruleKey, ':max') => $actual <= $this->allowedValue,
            str_ends_with($this->ruleKey, ':min') => $actual >= $this->allowedValue,
            default => abs($actual - $this->allowedValue) < 1e-9,
        };
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFormation(): Formation
    {
        return $this->formation;
    }

    public function getNodeId(): ?string
    {
        return $this->nodeId;
    }

    public function getNodeLabel(): ?string
    {
        return $this->nodeLabel;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getStatus(): DerogationStatus
    {
        return $this->status;
    }

    public function isPending(): bool
    {
        return DerogationStatus::Pending === $this->status;
    }

    public function resolve(DerogationStatus $status, ?string $adminNote): self
    {
        $this->status = $status;
        $this->adminNote = trim((string) $adminNote) ?: null;
        $this->resolvedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getAdminNote(): ?string
    {
        return $this->adminNote;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getResolvedAt(): ?\DateTimeImmutable
    {
        return $this->resolvedAt;
    }
}
