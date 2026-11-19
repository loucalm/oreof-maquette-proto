<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\FicheMatiereRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Fiche matière : entité partagée entre les EC qui la référencent. Porte les valeurs
 * des champs de son formulaire et les valeurs par défaut transmises aux EC.
 */
#[ORM\Entity(repositoryClass: FicheMatiereRepository::class)]
#[ORM\HasLifecycleCallbacks]
class FicheMatiere
{
    /** Clés de valeurs par défaut qu'une fiche peut imposer aux EC (jamais les compétences). */
    public const IMPOSABLE = ['hours', 'ects', 'mccc'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Copie du champ de formulaire `libelle`, pour lister et rechercher. */
    #[ORM\Column(length: 250)]
    private string $libelle = '';

    /** Formation porteuse ; null pour une fiche hors diplôme. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?Formation $formation = null;

    /** Nœud parcours porteur ; null pour le parcours unique d'une formation sans parcours. */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $parcoursNid = null;

    #[ORM\Column]
    private bool $horsDiplome = false;

    /** Type de diplôme d'une fiche hors diplôme (facultatif). */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?TypeDiplome $typeDiplome = null;

    /**
     * Valeurs des champs du formulaire de fiche : clé de champ → valeur.
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $data = [];

    /**
     * Valeurs par défaut transmises aux EC : `hours`, `ects`, `mccc`, `competencies`.
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $defaults = [];

    /**
     * Valeurs imposées aux EC : `{hours: bool, ects: bool, mccc: bool}`.
     *
     * @var array<string, bool>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $imposed = [];

    /** Place réservée au cycle de vie ; jamais lue ni écrite par le prototype. */
    #[ORM\Column(length: 40, nullable: true)]
    private ?string $etat = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[Assert\Callback]
    public function validatePorteur(ExecutionContextInterface $context): void
    {
        if ($this->horsDiplome === (null !== $this->formation)) {
            $context->buildViolation('Une fiche est soit hors diplôme (sans formation), soit portée par une formation.')
                ->atPath('formation')
                ->addViolation();
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLibelle(): string
    {
        return $this->libelle;
    }

    public function getFormation(): ?Formation
    {
        return $this->formation;
    }

    public function setFormation(?Formation $formation): self
    {
        $this->formation = $formation;

        return $this;
    }

    public function getParcoursNid(): ?string
    {
        return $this->parcoursNid;
    }

    public function setParcoursNid(?string $parcoursNid): self
    {
        $this->parcoursNid = $parcoursNid;

        return $this;
    }

    public function isHorsDiplome(): bool
    {
        return $this->horsDiplome;
    }

    public function setHorsDiplome(bool $horsDiplome): self
    {
        $this->horsDiplome = $horsDiplome;

        return $this;
    }

    public function getTypeDiplome(): ?TypeDiplome
    {
        return $this->typeDiplome;
    }

    public function setTypeDiplome(?TypeDiplome $typeDiplome): self
    {
        $this->typeDiplome = $typeDiplome;

        return $this;
    }

    /** @return array<string, mixed> */
    public function getData(): array
    {
        return $this->data;
    }

    /** @param array<string, mixed> $data */
    public function setData(array $data): self
    {
        $this->data = $data;
        $libelle = $data['libelle'] ?? '';
        $this->libelle = \is_string($libelle) ? $libelle : '';

        return $this;
    }

    public function getDataValue(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }

    /** @return array<string, mixed> */
    public function getDefaults(): array
    {
        return $this->defaults;
    }

    /** @param array<string, mixed> $defaults */
    public function setDefaults(array $defaults): self
    {
        $this->defaults = $defaults;

        return $this;
    }

    public function getDefault(string $key): mixed
    {
        return $this->defaults[$key] ?? null;
    }

    public function setDefault(string $key, mixed $v): self
    {
        $this->defaults[$key] = $v;

        return $this;
    }

    /** @return array<string, bool> */
    public function getImposed(): array
    {
        return $this->imposed;
    }

    public function isImposed(string $key): bool
    {
        return true === ($this->imposed[$key] ?? false);
    }

    public function setImposed(string $key, bool $on): self
    {
        if (!\in_array($key, self::IMPOSABLE, true)) {
            throw new \InvalidArgumentException(\sprintf('« %s » ne peut pas être imposé par une fiche.', $key));
        }
        $this->imposed[$key] = $on;

        return $this;
    }

    public function getEtat(): ?string
    {
        return $this->etat;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
