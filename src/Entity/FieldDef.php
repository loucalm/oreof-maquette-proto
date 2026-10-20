<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\FieldDefRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Définition d'un champ de formulaire de nœud — piloté par les données.
 *
 * L'admin décide quels champs existent, dans quel onglet (`tab`) ils vivent,
 * sous quelle catégorie, leur type de saisie, s'ils sont requis, etc.
 * Chaque champ correspond à une « capacité » que les types de nœud peuvent
 * activer (NodeType::capabilities).
 */
#[ORM\Entity(repositoryClass: FieldDefRepository::class)]
#[ORM\Table(name: 'field_def')]
class FieldDef
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Identifiant stable = clé de capacité (ex. "ects", "nature"). */
    #[ORM\Column(length: 64, unique: true)]
    private string $key;

    #[ORM\Column(length: 120)]
    private string $label;

    /** Onglet du formulaire : "props", "volume_horaire", "mccc" ou un onglet créé par l'admin. */
    #[ORM\Column(length: 40)]
    private string $tab = 'props';

    /** Clé d'un type de saisie (App\FieldType\FieldTypeRegistry), ex. "text". */
    #[ORM\Column(length: 20)]
    private string $type = 'text';

    /**
     * Options inline pour type = "choice"/"radio"/"checkbox" : { valeur: libellé }.
     * Ignoré si `referentielKey` est renseigné.
     *
     * @var array<string, string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $options = [];

    /** Si renseigné (types qui proposent le réglage « referentiel », cf. App\FieldType), les options viennent de `referentiel(referentielKey)`. */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $referentielKey = null;

    /** Autorise une valeur libre en plus des options (uniquement pertinent pour choice/checkbox). */
    #[ORM\Column]
    private bool $allowExtra = false;

    /** Propose un mini-formulaire d'ajout direct au référentiel associé, sans quitter la saisie. */
    #[ORM\Column]
    private bool $quickAdd = false;

    /** Libellé de l'option vide d'une liste de choix (ex. « Choisir un type d'UE »). */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $placeholder = null;

    #[ORM\Column]
    private bool $required = false;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $help = null;

    /** Bornes min / max ; leur sens dépend du type (FieldTypeInterface::bounds() : valeur, longueur, nombre de choix). */
    #[ORM\Column(nullable: true)]
    private ?float $min = null;

    #[ORM\Column(nullable: true)]
    private ?float $max = null;

    #[ORM\Column]
    private int $position = 0;

    /** Champ livré par les fixtures (informatif). */
    #[ORM\Column]
    private bool $system = false;

    /**
     * Champ verrouillé : visible et déplaçable dans le constructeur de formulaire du
     * type, mais ni éditable ni retirable depuis là (cf. NodeTypeController::assertFieldEditable()).
     * Sert aux quelques champs qui écrivent sur une propriété d'entité dédiée plutôt
     * que sur le blob générique d'attributs (ex. nom/diplôme d'une formation) — leur
     * redéfinir label/type/etc. depuis ce formulaire casserait la logique de
     * sauvegarde qui leur est propre —, et au « minimum requis » métier (responsables
     * RF / RP, stockés eux dans le blob). Sans rapport avec NodeType::lockedCapabilities
     * (qui empêche de désactiver une capacité en aval, pas d'éditer sa définition).
     */
    #[ORM\Column]
    private bool $locked = false;

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

    public function getTab(): string
    {
        return $this->tab;
    }

    public function setTab(string $tab): self
    {
        $this->tab = trim($tab) ?: 'props';

        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): self
    {
        $this->type = $type;

        return $this;
    }

    /** @return array<string, string> */
    public function getOptions(): array
    {
        return $this->options;
    }

    /** @param array<string, string> $options */
    public function setOptions(array $options): self
    {
        $this->options = $options;

        return $this;
    }

    public function getReferentielKey(): ?string
    {
        return $this->referentielKey;
    }

    public function setReferentielKey(?string $referentielKey): self
    {
        $this->referentielKey = trim((string) $referentielKey) ?: null;

        return $this;
    }

    public function isAllowExtra(): bool
    {
        return $this->allowExtra;
    }

    public function setAllowExtra(bool $allowExtra): self
    {
        $this->allowExtra = $allowExtra;

        return $this;
    }

    public function isQuickAdd(): bool
    {
        return $this->quickAdd;
    }

    public function setQuickAdd(bool $quickAdd): self
    {
        $this->quickAdd = $quickAdd;

        return $this;
    }

    public function getPlaceholder(): ?string
    {
        return $this->placeholder;
    }

    public function setPlaceholder(?string $placeholder): self
    {
        $this->placeholder = trim((string) $placeholder) ?: null;

        return $this;
    }

    public function getMin(): ?float
    {
        return $this->min;
    }

    public function setMin(?float $min): self
    {
        $this->min = $min;

        return $this;
    }

    public function getMax(): ?float
    {
        return $this->max;
    }

    public function setMax(?float $max): self
    {
        $this->max = $max;

        return $this;
    }

    public function isRequired(): bool
    {
        return $this->required;
    }

    public function setRequired(bool $required): self
    {
        $this->required = $required;

        return $this;
    }

    public function getHelp(): ?string
    {
        return $this->help;
    }

    public function setHelp(?string $help): self
    {
        $this->help = trim((string) $help) ?: null;

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

    public function isLocked(): bool
    {
        return $this->locked;
    }

    public function setLocked(bool $locked): self
    {
        $this->locked = $locked;

        return $this;
    }
}
