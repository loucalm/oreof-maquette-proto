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
    /** Types de saisie. Les trois avant-derniers sont « système » (rendu spécial) ; « separator » n'est pas un champ saisissable. */
    public const TYPES = [
        'text' => 'Texte court',
        'textarea' => 'Texte long',
        'number' => 'Nombre',
        'choice' => 'Liste de choix',
        'radio' => 'Choix unique (boutons radio)',
        'checkbox' => 'Case à cocher (choix multiple)',
        'competencies' => 'Compétences (référentiel)',
        'hours' => 'Volume horaire (présentiel / distanciel / TE)',
        'mccc' => 'MCCC (type de contrôle)',
        'separator' => 'Séparateur (ligne d’organisation)',
    ];

    /** Types dont les options peuvent venir d'un référentiel plutôt que d'une saisie inline. */
    public const REFERENTIEL_CAPABLE_TYPES = ['choice', 'radio', 'checkbox'];

    public const SYSTEM_TYPES = ['competencies', 'hours', 'mccc'];

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

    /** Une des clés de self::TYPES. */
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

    /** Si renseigné (types de REFERENTIEL_CAPABLE_TYPES), les options viennent de `referentiel(referentielKey)`. */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $referentielKey = null;

    /** Autorise une valeur libre en plus des options (uniquement pertinent pour choice/checkbox). */
    #[ORM\Column]
    private bool $allowExtra = false;

    /** Propose un mini-formulaire d'ajout direct au référentiel associé, sans quitter la saisie. */
    #[ORM\Column]
    private bool $quickAdd = false;

    #[ORM\Column]
    private bool $required = false;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $help = null;

    /** Bornes pour type = "number" (et longueur max pour text/textarea). */
    #[ORM\Column(nullable: true)]
    private ?float $min = null;

    #[ORM\Column(nullable: true)]
    private ?float $max = null;

    #[ORM\Column]
    private int $position = 0;

    /** Champ livré par les fixtures (informatif). */
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
        $this->type = \array_key_exists($type, self::TYPES) ? $type : 'text';

        return $this;
    }

    public function isSystemType(): bool
    {
        return \in_array($this->type, self::SYSTEM_TYPES, true);
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

    public function isReferentielCapable(): bool
    {
        return \in_array($this->type, self::REFERENTIEL_CAPABLE_TYPES, true);
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
}
