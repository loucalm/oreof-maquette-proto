<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\StructureTemplateRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Modèle de structure : une base réutilisable, instanciée dans une formation
 * puis librement modifiable. Ex. « BUT », « Licence LMD ».
 *
 * `tree` est un arbre JSON de la forme :
 *   [{ "type": "annee", "label": "Année 1", "code": null,
 *      "attributes": {...}, "children": [ ... ] }, ...]
 */
#[ORM\Entity(repositoryClass: StructureTemplateRepository::class)]
#[ORM\Table(name: 'structure_template')]
class StructureTemplate
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64, unique: true)]
    private string $key;

    #[ORM\Column(length: 120)]
    private string $label;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /**
     * Type de diplôme auquel ce modèle est rattaché (un seul modèle par diplôme).
     * À la création d'une formation de ce diplôme, le modèle est appliqué
     * automatiquement et ses nœuds « imposés » (locked) verrouillent la structure.
     */
    #[ORM\Column(length: 80, nullable: true, unique: true)]
    private ?string $diplome = null;

    /** Si le modèle suppose des parcours (multi) ou non (mono). */
    #[ORM\Column]
    private bool $avecParcours = false;

    /**
     * Tableau structurel : la chaîne de types qui compose le template (« 0
     * Parcours, 1 Année, 2 Semestre, 3 UE, 4 EC »), fixée avant toute
     * instanciation. Contraint les types proposables dans `tree` à chaque
     * profondeur — remplace la déduction a posteriori depuis l'arbre.
     *
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $structure = [];

    /**
     * @var list<array{type: string, label?: string, code?: string|null, attributes?: array<string, mixed>, children?: list<mixed>}>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $tree = [];

    /** Unité de temps par défaut du diplôme (ex. "Année"), copiée sur la formation à l'application. */
    #[ORM\Column(length: 40, nullable: true)]
    private ?string $calendarUnit = null;

    /** Durée par défaut en nombre d'unités, copiée sur la formation à l'application. */
    #[ORM\Column(nullable: true)]
    private ?int $calendarSpan = null;

    /**
     * Surcharge de capacités par type utilisé dans ce template : clé =
     * `NodeType::key`, valeur = `{capacité: bool}` (seulement les capacités
     * non verrouillées globalement par le type peuvent être surchargées ici).
     *
     * @var array<string, array<string, bool>>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $fieldOverrides = [];

    /**
     * Types de MCCC disponibles pour ce diplôme et profil de règles choisi
     * pour chacun : clé = `MccType::key`, valeur = `profile.key`.
     *
     * @var array<string, string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $mcccProfiles = [];

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

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function getDiplome(): ?string
    {
        return $this->diplome;
    }

    public function setDiplome(?string $diplome): self
    {
        $this->diplome = trim((string) $diplome) ?: null;

        return $this;
    }

    public function isAvecParcours(): bool
    {
        return $this->avecParcours;
    }

    public function setAvecParcours(bool $avecParcours): self
    {
        $this->avecParcours = $avecParcours;

        return $this;
    }

    /** @return list<array<string, mixed>> */
    public function getTree(): array
    {
        return $this->tree;
    }

    /** @param list<array<string, mixed>> $tree */
    public function setTree(array $tree): self
    {
        $this->tree = $tree;

        return $this;
    }

    /** @return list<string> */
    public function getStructure(): array
    {
        return $this->structure;
    }

    /** @param list<string> $structure */
    public function setStructure(array $structure): self
    {
        $this->structure = array_values($structure);

        return $this;
    }

    public function getCalendarUnit(): ?string
    {
        return $this->calendarUnit;
    }

    public function setCalendarUnit(?string $calendarUnit): self
    {
        $this->calendarUnit = trim((string) $calendarUnit) ?: null;

        return $this;
    }

    public function getCalendarSpan(): ?int
    {
        return $this->calendarSpan;
    }

    public function setCalendarSpan(?int $calendarSpan): self
    {
        $this->calendarSpan = $calendarSpan;

        return $this;
    }

    /** @return array<string, array<string, bool>> */
    public function getFieldOverrides(): array
    {
        return $this->fieldOverrides;
    }

    /** @param array<string, array<string, bool>> $fieldOverrides */
    public function setFieldOverrides(array $fieldOverrides): self
    {
        $this->fieldOverrides = $fieldOverrides;

        return $this;
    }

    /** @return array<string, bool> */
    public function getFieldOverridesFor(string $typeKey): array
    {
        return $this->fieldOverrides[$typeKey] ?? [];
    }

    /** @param array<string, bool> $overrides */
    public function setFieldOverridesFor(string $typeKey, array $overrides): self
    {
        if ($overrides === []) {
            unset($this->fieldOverrides[$typeKey]);
        } else {
            $this->fieldOverrides[$typeKey] = $overrides;
        }

        return $this;
    }

    /** @return array<string, string> */
    public function getMcccProfiles(): array
    {
        return $this->mcccProfiles;
    }

    /** @param array<string, string> $mcccProfiles */
    public function setMcccProfiles(array $mcccProfiles): self
    {
        $this->mcccProfiles = $mcccProfiles;

        return $this;
    }
}
