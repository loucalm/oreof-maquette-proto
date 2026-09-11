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
 * Le socle ne connaît AUCUNE hiérarchie en dur : le type déclare seulement ce
 * qu'il porte (`capabilities`) et lesquelles sont réservées à l'admin. Qui peut
 * être enfant de qui est décidé par le squelette de CHAQUE formation
 * (Formation::structure), pas par le type.
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

    /**
     * Ce type porte-t-il une référence hiérarchique calculée (« Année 1 »,
     * « UE 1.1 ») ? Les niveaux non numérotés (ex. Semestre) sont « transparents »
     * pour la numérotation : leurs enfants héritent du contexte du parent numéroté.
     */
    #[ORM\Column]
    private bool $numbered = false;

    /** Style de l'indice : "decimal" (1, 2…), "alpha" (a, b…), "roman" (i, ii…). */
    #[ORM\Column(length: 12)]
    private string $numberStyle = 'decimal';

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

    public const NUMBER_STYLES = [
        'decimal' => 'Décimal (1, 2, 3…)',
        'alpha' => 'Alphabétique (a, b, c…)',
        'alpha_upper' => 'Alphabétique majuscule (A, B, C…)',
        'roman' => 'Romain (i, ii, iii…)',
    ];

    public function isNumbered(): bool
    {
        return $this->numbered;
    }

    public function setNumbered(bool $numbered): self
    {
        $this->numbered = $numbered;

        return $this;
    }

    public function getNumberStyle(): string
    {
        return $this->numberStyle;
    }

    public function setNumberStyle(string $style): self
    {
        $this->numberStyle = \array_key_exists($style, self::NUMBER_STYLES) ? $style : 'decimal';

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
