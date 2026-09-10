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
    private bool $multiParcours = false;

    /**
     * @var list<array{type: string, label?: string, code?: string|null, attributes?: array<string, mixed>, children?: list<mixed>}>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $tree = [];

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

    public function isMultiParcours(): bool
    {
        return $this->multiParcours;
    }

    public function setMultiParcours(bool $multiParcours): self
    {
        $this->multiParcours = $multiParcours;

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
}
