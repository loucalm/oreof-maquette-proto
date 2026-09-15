<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\DerogationStatus;
use App\Repository\DerogationRequestRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Demande de dérogation à la structure imposée par le template du diplôme —
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
