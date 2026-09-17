<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\RefCategory;
use App\Repository\ReferentielRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Une liste de valeurs de nomenclature proposée dans les formulaires
 * (types de diplôme, domaines, régimes d'inscription, langues…).
 *
 * Administrée dans /administration/referentiels, sur le même principe que
 * les champs de formulaire (FieldDef) : livrée par les fixtures, puis
 * entièrement modifiable (valeurs ajoutées/renommées/supprimées, nouvelles
 * listes créées) sans toucher au code.
 */
#[ORM\Entity(repositoryClass: ReferentielRepository::class)]
#[ORM\Table(name: 'referentiel')]
class Referentiel
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Identifiant stable utilisé par les formulaires (ex. "diplomes"). */
    #[ORM\Column(length: 64, unique: true)]
    private string $key;

    #[ORM\Column(length: 120)]
    private string $label;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /**
     * Valeurs de la liste, ordonnées : valeur stockée => libellé affiché.
     *
     * @var array<string, string>
     */
    #[ORM\Column(name: 'valeurs', type: Types::JSON)]
    private array $values = [];

    #[ORM\Column]
    private int $position = 0;

    /** Livré par les fixtures — protège seulement de la suppression (des gabarits l'utilisent par sa clé). */
    #[ORM\Column]
    private bool $system = false;

    #[ORM\Column(length: 20, enumType: RefCategory::class)]
    private RefCategory $category = RefCategory::Libre;

    /** Note libre : quelle entité réelle ce référentiel devra référencer dans l'application finale (catégorie « Lier à des entités »). */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $entiteCible = null;

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
        $this->description = trim((string) $description) ?: null;

        return $this;
    }

    /** @return array<string, string> */
    public function getValues(): array
    {
        return $this->values;
    }

    /** @param array<string, string> $values */
    public function setValues(array $values): self
    {
        $this->values = $values;

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

    public function isSystem(): bool
    {
        return $this->system;
    }

    public function setSystem(bool $system): self
    {
        $this->system = $system;

        return $this;
    }

    public function getCategory(): RefCategory
    {
        return $this->category;
    }

    public function setCategory(RefCategory $category): self
    {
        $this->category = $category;

        return $this;
    }

    public function getEntiteCible(): ?string
    {
        return $this->entiteCible;
    }

    public function setEntiteCible(?string $entiteCible): self
    {
        $this->entiteCible = trim((string) $entiteCible) ?: null;

        return $this;
    }
}
