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
    /** Types de saisie. Les trois derniers sont « système » (rendu spécial). */
    public const TYPES = [
        'text' => 'Texte court',
        'textarea' => 'Texte long',
        'number' => 'Nombre',
        'choice' => 'Liste de choix',
        'competencies' => 'Compétences (référentiel)',
        'hours' => 'Volume horaire (présentiel / distanciel / TE)',
        'mccc' => 'MCCC (type de contrôle)',
    ];

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

    /** Sous-titre de regroupement dans l'onglet (facultatif). */
    #[ORM\Column(length: 80, nullable: true)]
    private ?string $category = null;

    /** Une des clés de self::TYPES. */
    #[ORM\Column(length: 20)]
    private string $type = 'text';

    /**
     * Options pour type = "choice" : { valeur: libellé }.
     *
     * @var array<string, string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $options = [];

    #[ORM\Column]
    private bool $required = false;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $help = null;

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

    public function getCategory(): ?string
    {
        return $this->category;
    }

    public function setCategory(?string $category): self
    {
        $this->category = trim((string) $category) ?: null;

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
