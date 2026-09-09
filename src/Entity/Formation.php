<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\FormationRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: FormationRepository::class)]
class Formation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 200)]
    private string $name;

    /** Libre dans le proto : "Licence", "BUT", "Master"… */
    #[ORM\Column(length: 80, nullable: true)]
    private ?string $diplome = null;

    #[ORM\Column(length: 160, nullable: true)]
    private ?string $domaine = null;

    #[ORM\Column(length: 160, nullable: true)]
    private ?string $composante = null;

    /** « La formation contient-elle des parcours ? » */
    #[ORM\Column]
    private bool $multiParcours = false;

    /** Cible d'ECTS total (surtout utile en mono-parcours). */
    #[ORM\Column(nullable: true)]
    private ?int $ectsTotal = null;

    /**
     * Sections « Paramètre de la formation » (organisation, présentation…).
     * Champs libres du prototype, une clé par section.
     *
     * @var array<string, array<string, mixed>>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $parametres = [];

    /**
     * Squelette de la formation : chaîne ordonnée de clés de type, du plus haut
     * niveau du « corps » vers la feuille — ex. ['annee','semestre','ue','ec'].
     * Le niveau « parcours » n'y figure jamais : il est implicite quand la
     * formation est multi-parcours (cf. getEffectiveStructure()).
     *
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $structure = [];

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, Node> */
    #[ORM\OneToMany(targetEntity: Node::class, mappedBy: 'formation', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $nodes;

    public function __construct(string $name)
    {
        $this->name = $name;
        $this->createdAt = new \DateTimeImmutable();
        $this->nodes = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getDiplome(): ?string
    {
        return $this->diplome;
    }

    public function setDiplome(?string $diplome): self
    {
        $this->diplome = $diplome;

        return $this;
    }

    public function getDomaine(): ?string
    {
        return $this->domaine;
    }

    public function setDomaine(?string $domaine): self
    {
        $this->domaine = $domaine;

        return $this;
    }

    public function getComposante(): ?string
    {
        return $this->composante;
    }

    public function setComposante(?string $composante): self
    {
        $this->composante = $composante;

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

    public function getEctsTotal(): ?int
    {
        return $this->ectsTotal;
    }

    public function setEctsTotal(?int $ectsTotal): self
    {
        $this->ectsTotal = $ectsTotal;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** @return array<string, mixed> */
    public function getParametre(string $section): array
    {
        return $this->parametres[$section] ?? [];
    }

    /** @param array<string, mixed> $data */
    public function setParametre(string $section, array $data): self
    {
        $this->parametres[$section] = $data;

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
        $seen = [];
        $this->structure = array_values(array_filter(
            array_map('strval', $structure),
            static function (string $k) use (&$seen): bool {
                if ($k === '' || $k === 'parcours' || isset($seen[$k])) {
                    return false;
                }
                $seen[$k] = true;

                return true;
            },
        ));

        return $this;
    }

    /**
     * Chaîne effective, niveau « parcours » inclus si la formation est
     * multi-parcours.
     *
     * @return list<string>
     */
    public function getEffectiveStructure(): array
    {
        // le niveau « parcours » fait toujours partie du squelette : en
        // multi-parcours c'est un vrai nœud, en mono la formation joue ce rôle.
        return array_merge(['parcours'], $this->structure);
    }

    /** true = mono-parcours : pas de nœud « parcours », la formation l'incarne. */
    public function isMono(): bool
    {
        return !$this->multiParcours;
    }

    /** Clé de type de la racine effective (pour la création de nœuds). */
    public function getRootTypeKey(): ?string
    {
        return $this->getEffectiveStructure()[0] ?? null;
    }

    /**
     * Racine VISIBLE de l'arbre : « parcours » en multi, sinon le 1er maillon
     * du corps (le niveau parcours est invisible en mono).
     */
    public function getVisibleRootTypeKey(): ?string
    {
        return $this->multiParcours ? 'parcours' : ($this->structure[0] ?? null);
    }

    /** Clé de type des enfants d'un nœud de type $typeKey, d'après le squelette. */
    public function getChildTypeKey(string $typeKey): ?string
    {
        $chain = $this->getEffectiveStructure();
        $i = array_search($typeKey, $chain, true);

        return $i === false ? null : ($chain[$i + 1] ?? null);
    }

    /** Clé de type qui, dans le squelette, a $typeKey pour enfant (null si racine). */
    public function getParentTypeKey(string $typeKey): ?string
    {
        $chain = $this->getEffectiveStructure();
        $i = array_search($typeKey, $chain, true);
        if ($i === false || $i === 0) {
            return null;
        }
        $parent = $chain[$i - 1];

        // en mono, le niveau « parcours » n'a pas de nœud → ses enfants sont racine
        return ('parcours' === $parent && $this->isMono()) ? null : $parent;
    }

    /** $childTypeKey peut-il être enfant de $parentTypeKey selon le squelette ? */
    public function canParentTypes(string $parentTypeKey, string $childTypeKey): bool
    {
        return $this->getChildTypeKey($parentTypeKey) === $childTypeKey;
    }

    /** Un nœud de ce type est-il une feuille (aucun enfant prévu par le squelette) ? */
    public function isLeafType(string $typeKey): bool
    {
        return $this->getChildTypeKey($typeKey) === null;
    }

    /** Un nœud de ce type peut-il être à la racine VISIBLE de l'arbre ? */
    public function canBeRootType(string $typeKey): bool
    {
        return $this->getVisibleRootTypeKey() === $typeKey;
    }

    /** @return Collection<int, Node> */
    public function getNodes(): Collection
    {
        return $this->nodes;
    }

    public function addNode(Node $node): self
    {
        if (!$this->nodes->contains($node)) {
            $this->nodes->add($node);
            $node->setFormation($this);
        }

        return $this;
    }

    public function removeNode(Node $node): self
    {
        $this->nodes->removeElement($node);

        return $this;
    }

    /** @return list<Node> Nœuds racine (sans parent), triés. */
    public function getRootNodes(): array
    {
        $roots = array_filter($this->nodes->toArray(), static fn (Node $n) => $n->getParent() === null);
        usort($roots, static fn (Node $a, Node $b) => $a->getPosition() <=> $b->getPosition());

        return array_values($roots);
    }
}
