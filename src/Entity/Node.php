<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\NodeFamily;
use App\Repository\NodeRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Un nœud de la maquette. Liste d'adjacence (parent + position) : la structure
 * est un arbre libre, aucune profondeur imposée par le socle.
 */
#[ORM\Entity(repositoryClass: NodeRepository::class)]
#[ORM\Index(fields: ['formation', 'parent'], name: 'idx_node_formation_parent')]
class Node
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'nodes')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Formation $formation = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private NodeType $type;

    #[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'children')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?self $parent = null;

    /** @var Collection<int, Node> */
    #[ORM\OneToMany(targetEntity: self::class, mappedBy: 'parent', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $children;

    #[ORM\Column]
    private int $position = 0;

    #[ORM\Column(length: 200)]
    private string $label = '';

    #[ORM\Column(length: 60, nullable: true)]
    private ?string $code = null;

    /**
     * Sac d'attributs normalisés (voir App\Maquette\AttributeCatalog).
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $attributes = [];

    /**
     * Surcharges de capacités propres à ce nœud (écran « Paramètre du nœud »).
     * null => on utilise telles quelles les capabilities du type.
     *
     * @var array<string, bool>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $capabilityOverrides = null;

    /** « Mutualisé » = mis à disposition de toutes les formations. */
    #[ORM\Column]
    private bool $mutualized = false;

    /**
     * Si ce nœud a été « raccroché » (récupéré d'un nœud mutualisé d'ailleurs),
     * on garde la trace de la source.
     */
    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?self $mutualizedFrom = null;

    /**
     * Parent d'un parcours dans l'arborescence inter-parcours (ramification).
     * N'a de sens que pour les nœuds de type « parcours ».
     */
    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?self $parcoursParent = null;

    public function __construct(NodeType $type, string $label = '')
    {
        $this->type = $type;
        $this->label = $label;
        $this->children = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getType(): NodeType
    {
        return $this->type;
    }

    public function setType(NodeType $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function getParent(): ?self
    {
        return $this->parent;
    }

    public function setParent(?self $parent): self
    {
        $this->parent = $parent;

        return $this;
    }

    /** @return Collection<int, Node> */
    public function getChildren(): Collection
    {
        return $this->children;
    }

    public function addChild(self $child): self
    {
        if (!$this->children->contains($child)) {
            $this->children->add($child);
            $child->setParent($this);
            $child->setFormation($this->formation);
        }

        return $this;
    }

    public function removeChild(self $child): self
    {
        $this->children->removeElement($child);

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

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): self
    {
        $this->label = $label;

        return $this;
    }

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function setCode(?string $code): self
    {
        $this->code = $code;

        return $this;
    }

    /** @return array<string, mixed> */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    /** @param array<string, mixed> $attributes */
    public function setAttributes(array $attributes): self
    {
        $this->attributes = $attributes;

        return $this;
    }

    public function getAttribute(string $key): mixed
    {
        return $this->attributes[$key] ?? null;
    }

    public function setAttribute(string $key, mixed $value): self
    {
        if ($value === null || $value === '' || $value === []) {
            unset($this->attributes[$key]);
        } else {
            $this->attributes[$key] = $value;
        }

        return $this;
    }

    /** @return array<string, bool>|null */
    public function getCapabilityOverrides(): ?array
    {
        return $this->capabilityOverrides;
    }

    /** @param array<string, bool>|null $overrides */
    public function setCapabilityOverrides(?array $overrides): self
    {
        $this->capabilityOverrides = $overrides;

        return $this;
    }

    /**
     * Capacités effectives : celles du type, écrasées par les surcharges du nœud.
     *
     * @return array<string, bool>
     */
    public function effectiveCapabilities(): array
    {
        $caps = [];
        foreach ($this->type->getCapabilities() as $name => $value) {
            $caps[$name] = (bool) $value;
        }
        foreach ($this->capabilityOverrides ?? [] as $name => $value) {
            // une capacité « réservée admin » ne peut pas être surchargée par le nœud
            if (!$this->type->isCapabilityLocked($name)) {
                $caps[$name] = (bool) $value;
            }
        }

        return $caps;
    }

    public function can(string $capability): bool
    {
        return $this->effectiveCapabilities()[$capability] ?? false;
    }

    public function isMutualized(): bool
    {
        return $this->mutualized;
    }

    public function setMutualized(bool $mutualized): self
    {
        $this->mutualized = $mutualized;

        return $this;
    }

    public function getMutualizedFrom(): ?self
    {
        return $this->mutualizedFrom;
    }

    public function setMutualizedFrom(?self $mutualizedFrom): self
    {
        $this->mutualizedFrom = $mutualizedFrom;

        return $this;
    }

    public function getParcoursParent(): ?self
    {
        return $this->parcoursParent;
    }

    public function setParcoursParent(?self $parcoursParent): self
    {
        $this->parcoursParent = $parcoursParent;

        return $this;
    }

    public function isParcours(): bool
    {
        return $this->type->getKey() === 'parcours';
    }

    /** Nœud du référentiel de compétences (bloc ou compétence), pas de la structure pédagogique. */
    public function isCompetenceNode(): bool
    {
        return $this->type->getFamily() === NodeFamily::Competence;
    }

    /** Bloc « compétences transversales (RNCP) » — au plus un par formation. */
    public function isTransversalBloc(): bool
    {
        return $this->isCompetenceNode()
            && $this->parent === null
            && (bool) ($this->attributes['transversal'] ?? false);
    }

    /** Description courte (sous-titre) — pour les blocs et compétences du BCC. */
    public function getDescription(): string
    {
        return trim((string) ($this->attributes['description'] ?? ''));
    }

    /** Période de début du parcours sur l'axe temporel (défaut 1). */
    public function getPeriodeDebut(): int
    {
        return max(1, (int) ($this->attributes['periodeDebut'] ?? $this->attributes['anneeDebut'] ?? 1));
    }

    /** Période de fin ; par défaut, début + (nb de périodes enfants - 1), min = début. */
    public function getPeriodeFin(): int
    {
        $stored = (int) ($this->attributes['periodeFin'] ?? $this->attributes['anneeFin'] ?? 0);
        if ($stored > 0) {
            return max($stored, $this->getPeriodeDebut());
        }
        // le type « période » = 1er niveau du squelette (enfant de « parcours »)
        $periodeKey = $this->formation?->getChildTypeKey('parcours');
        $nb = 0;
        foreach ($this->children as $c) {
            if ($c->getType()->getKey() === $periodeKey) {
                ++$nb;
            }
        }

        return $this->getPeriodeDebut() + max(0, $nb - 1);
    }

    /**
     * Règle de ramification entre parcours : un parcours « enfant » est une
     * spécialisation qui se sépare du parent. Il doit donc commencer au moins
     * une période après le début du parent, s'enchaîner sans coupure après sa
     * fin, et se prolonger au moins jusqu'à la fin du parent. Deux parcours sur
     * la même période sont parallèles, pas parent / enfant.
     */
    public static function parcoursPeriodsAllowChild(int $parentDebut, int $parentFin, int $childDebut, int $childFin): bool
    {
        return $childDebut > $parentDebut
            && $childDebut <= $parentFin + 1
            && $childFin >= $parentFin;
    }

    public function getDepth(): int
    {
        $depth = 0;
        $cursor = $this->parent;
        while ($cursor !== null) {
            ++$depth;
            $cursor = $cursor->getParent();
        }

        return $depth;
    }

    public function getDisplayLabel(): string
    {
        return $this->label !== '' ? $this->label : $this->type->getLabel();
    }
}
