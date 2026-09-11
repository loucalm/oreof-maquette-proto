<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\FormationRepository;
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
     * Dimension temporelle : nombre de périodes (3 ans, 6 mois, 12 semaines…).
     * null = déduit de l'arbre / des parcours.
     */
    #[ORM\Column(nullable: true)]
    private ?int $calendarSpan = null;

    /**
     * Libellé de l'unité de temps (« Année », « Mois », « Semaine »…).
     * null = libellé du type du 1er niveau du squelette.
     */
    #[ORM\Column(length: 40, nullable: true)]
    private ?string $calendarUnit = null;

    /**
     * DOCUMENT JSON 1/3 — propriétés de la formation.
     * Sections « Paramètre de la formation » (organisation, présentation,
     * structure…). Champs libres du prototype, une clé par section.
     *
     * @var array<string, array<string, mixed>>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $parametres = [];

    /**
     * DOCUMENT JSON 2/3 — propriétés des parcours (formations multi-parcours).
     * Map `nid → { parametres: { organisation: {…}, presentation: {…} } }`.
     * Vide en mono-parcours. Le service App\Maquette\Maquette en est l'unique
     * gestionnaire.
     *
     * @var array<string, array<string, mixed>>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $dataParcours = [];

    /**
     * DOCUMENT JSON 3/3 — arborescence + données des nœuds.
     * Arbre imbriqué : chaque nœud = { nid, type, label, code, attributes,
     * capabilityOverrides, locked, mutualized, mutualizedFrom, children[] }.
     * Le référentiel de compétences (BCC) y figure aussi (nœuds de famille
     * « compétence », arbre parallèle filtré à l'affichage pédagogique).
     * L'ordre du tableau porte la position. Manipulé uniquement via Maquette.
     *
     * @var list<array<string, mixed>>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $arbre = [];

    /**
     * Cache de progression, recalculé par Maquette::save() (Completion::docStats).
     * Évite de reconstruire l'arbre de chaque formation sur la page liste.
     * Forme : { progress, req, filled, parcours: [{nid,label,debut,fin,parent,progress,bcc}] }.
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $stats = [];

    /**
     * Un seul niveau de « rétablir » (redo) : l'état quitté par le dernier
     * « Annuler » depuis le bandeau d'actions, réappliqué par « Rétablir ».
     * Vide = rien à rétablir. Vidé par Maquette::save() dès qu'une modification
     * normale intervient (le rétablir ne survit pas à un nouveau changement).
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $redoSnapshot = [];

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

    public function __construct(string $name)
    {
        $this->name = $name;
        $this->createdAt = new \DateTimeImmutable();
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

    public function getCalendarSpan(): ?int
    {
        return $this->calendarSpan;
    }

    public function setCalendarSpan(?int $calendarSpan): self
    {
        $this->calendarSpan = ($calendarSpan !== null && $calendarSpan > 0) ? $calendarSpan : null;

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

    /** @return array<string, array<string, mixed>> */
    public function getParametres(): array
    {
        return $this->parametres;
    }

    /** @param array<string, array<string, mixed>> $parametres */
    public function setParametres(array $parametres): self
    {
        $this->parametres = $parametres;

        return $this;
    }

    /** @return array<string, array<string, mixed>> */
    public function getDataParcours(): array
    {
        return $this->dataParcours;
    }

    /** @param array<string, array<string, mixed>> $data */
    public function setDataParcours(array $data): self
    {
        $this->dataParcours = $data;

        return $this;
    }

    /** @return list<array<string, mixed>> */
    public function getArbre(): array
    {
        return $this->arbre;
    }

    /** @param list<array<string, mixed>> $arbre */
    public function setArbre(array $arbre): self
    {
        $this->arbre = $arbre;

        return $this;
    }

    /** @return array<string, mixed> */
    public function getStats(): array
    {
        return $this->stats;
    }

    /** @param array<string, mixed> $stats */
    public function setStats(array $stats): self
    {
        $this->stats = $stats;

        return $this;
    }

    /** @return array<string, mixed> */
    public function getRedoSnapshot(): array
    {
        return $this->redoSnapshot;
    }

    /** @param array<string, mixed> $snapshot */
    public function setRedoSnapshot(array $snapshot): self
    {
        $this->redoSnapshot = $snapshot;

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
}
