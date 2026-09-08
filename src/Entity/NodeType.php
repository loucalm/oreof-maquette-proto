<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\NodeFamily;
use App\Repository\NodeTypeRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Type de nœud — le métamodèle, entièrement piloté par les données.
 *
 * Le socle ne connaît AUCUNE hiérarchie en dur : c'est ici que le responsable
 * déclare quels types existent, ce qu'ils portent (`capabilities`) et qui peut
 * être enfant de qui (`allowedChildKeys`).
 */
#[ORM\Entity(repositoryClass: NodeTypeRepository::class)]
#[ORM\Table(name: 'node_type')]
class NodeType
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Identifiant stable, ex. "ue", "semestre", "bloc_competences". */
    #[ORM\Column(length: 64, unique: true)]
    private string $key;

    #[ORM\Column(length: 120)]
    private string $label;

    /** Nom d'icône (libre — le proto affiche un emoji ou un label). */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $icon = null;

    #[ORM\Column(length: 20, enumType: NodeFamily::class)]
    private NodeFamily $family = NodeFamily::Structural;

    /** Ordre d'affichage dans les sélecteurs. */
    #[ORM\Column]
    private int $position = 0;

    /**
     * Clés des types autorisés comme enfants.
     * `[]` = feuille. `["*"]` = n'importe quel type.
     *
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $allowedChildKeys = [];

    /**
     * Capacités par défaut : quels attributs ce type expose.
     * Clés connues : ects, ectsTarget, ueType, nature, competencies,
     * ficheMatiere, hours, mccc, mutualisable.
     *
     * @var array<string, bool|int|null>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $capabilities = [];

    /**
     * Capacités « réservées admin » : présentes sur le type mais que le
     * responsable de formation ne peut pas activer/désactiver par nœud
     * (l'override du nœud est ignoré pour ces clés).
     *
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $lockedCapabilities = [];

    /** Cible d'ECTS pour l'agrégation (30 semestre, 60 année…), ou null. */
    #[ORM\Column(nullable: true)]
    private ?int $ectsTarget = null;

    /** Type "socle" livré par les fixtures — informatif, non bloquant dans le proto. */
    #[ORM\Column]
    private bool $system = false;

    public function __construct(string $key, string $label)
    {
        $this->key = $key;
        $this->label = $label;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function setKey(string $key): self
    {
        $this->key = $key;

        return $this;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): self
    {
        $this->label = $label;

        return $this;
    }

    public function getIcon(): ?string
    {
        return $this->icon;
    }

    public function setIcon(?string $icon): self
    {
        $this->icon = $icon;

        return $this;
    }

    public function getFamily(): NodeFamily
    {
        return $this->family;
    }

    public function setFamily(NodeFamily $family): self
    {
        $this->family = $family;

        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): self
    {
        $this->position = $position;

        return $this;
    }

    /** @return list<string> */
    public function getAllowedChildKeys(): array
    {
        return $this->allowedChildKeys;
    }

    /** @param list<string> $keys */
    public function setAllowedChildKeys(array $keys): self
    {
        $this->allowedChildKeys = array_values($keys);

        return $this;
    }

    public function allowsChild(string $childKey): bool
    {
        return \in_array('*', $this->allowedChildKeys, true)
            || \in_array($childKey, $this->allowedChildKeys, true);
    }

    public function isLeaf(): bool
    {
        return $this->allowedChildKeys === [];
    }

    /** @return array<string, bool|int|null> */
    public function getCapabilities(): array
    {
        return $this->capabilities;
    }

    /** @param array<string, bool|int|null> $capabilities */
    public function setCapabilities(array $capabilities): self
    {
        $this->capabilities = $capabilities;

        return $this;
    }

    public function hasCapability(string $name): bool
    {
        return \array_key_exists($name, $this->capabilities);
    }

    /** Valeur par défaut (activé/désactivé) de la capacité pour ce type. */
    public function capabilityDefault(string $name): bool
    {
        return (bool) ($this->capabilities[$name] ?? false);
    }

    /** @return list<string> */
    public function getLockedCapabilities(): array
    {
        return $this->lockedCapabilities;
    }

    /** @param list<string> $keys */
    public function setLockedCapabilities(array $keys): self
    {
        // on ne verrouille que des capacités effectivement portées par le type
        $this->lockedCapabilities = array_values(array_filter(
            array_unique(array_map('strval', $keys)),
            fn (string $k) => \array_key_exists($k, $this->capabilities),
        ));

        return $this;
    }

    public function isCapabilityLocked(string $name): bool
    {
        return \in_array($name, $this->lockedCapabilities, true);
    }

    public function getEctsTarget(): ?int
    {
        return $this->ectsTarget;
    }

    public function setEctsTarget(?int $ectsTarget): self
    {
        $this->ectsTarget = $ectsTarget;

        return $this;
    }

    public function isSystem(): bool
    {
        return $this->system;
    }

    public function setSystem(bool $system): self
    {
        $this->system = $system;

        return $this;
    }
}
