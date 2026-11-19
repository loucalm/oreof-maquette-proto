<?php

declare(strict_types=1);

namespace App\FicheMatiere;

use App\Diplome\TypeDiplomeResolver;
use App\Entity\FicheMatiere;
use App\Maquette\Doc\MaquetteDoc;
use App\Maquette\Doc\TreeNode;
use App\Maquette\Maquette;
use App\Repository\FicheMatiereRepository;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Valeurs effectives d'un EC (spec fiche matière §7.1, §7.2) : `hours`, `ects`, `mccc`, `competencies`.
 *
 * Ordre : imposition par la fiche → source écrite dans `sources` (sinon source par défaut) → valeur de
 * cette source ; une source qui ne peut pas répondre retombe sur la valeur propre de l'EC, avec un
 * avertissement. Tout lecteur de ces valeurs passe par ici.
 */
final class EcValueResolver implements ResetInterface
{
    public const SOURCE_FICHE = 'fiche';
    public const SOURCE_EC = 'ec';
    public const SOURCE_PARENT = 'parent';
    public const SOURCE_PORTEUR = 'porteur';

    public const SOURCES = [self::SOURCE_FICHE, self::SOURCE_EC, self::SOURCE_PARENT, self::SOURCE_PORTEUR];
    public const KEYS = ['hours', 'ects', 'mccc', 'competencies'];

    private const WARN_INTROUVABLE = 'Fiche matière introuvable.';
    private const WARN_IMPOSSIBLE = 'Source impossible : valeur propre de l\'EC utilisée.';
    private const WARN_SANS_PORTEUR = 'Aucun EC du parcours porteur n\'utilise cette fiche.';
    private const WARN_PLUSIEURS = 'Plusieurs EC du parcours porteur utilisent cette fiche : le premier est repris.';
    private const WARN_BOUCLE = 'Reprise en boucle entre EC : valeur propre de l\'EC utilisée.';

    /** @var array<int, FicheMatiere> cache par requête, id → fiche trouvée */
    private array $fiches = [];

    /** @var \WeakMap<MaquetteDoc, array{rev: int, size: int, lists: array<string, list<TreeNode>>}> EC du parcours porteur par document et fiche, invalidés par MaquetteDoc::$revision */
    private \WeakMap $porteurCache;

    /** @var array<string, true> résolutions en cours (EC + clé), contre les reprises en boucle */
    private array $resolving = [];

    /**
     * @param \Closure(): Maquette $maquette fermeture : Maquette dépend (via Completion) des lecteurs
     *                                       de valeurs résolues, qui dépendent de ce service
     */
    /** Attribut de type d'EC que la fiche peut fixer (§6.5). */
    private const NATURE_KEY = 'natureElement';

    public function __construct(
        private readonly FicheMatiereRepository $repository,
        private readonly FicheMatiereAccess $access,
        #[AutowireServiceClosure(Maquette::class)]
        private readonly \Closure $maquette,
        private readonly TypeDiplomeResolver $diplomes,
    ) {
        $this->porteurCache = new \WeakMap();
    }

    /**
     * Attribut de l'EC fixé par sa fiche (§6.5) : le type d'EC (`natureElement`) quand la fiche en porte un
     * qui existe dans la version de l'EC (D5). Null sinon : l'EC garde le sien.
     */
    public function forcedValue(TreeNode $ec, string $key): mixed
    {
        if (self::NATURE_KEY !== $key || !$this->isElement($ec)) {
            return null;
        }
        $typeEc = $this->fiche($ec)?->getDataValue('typeEc');
        if (!\is_string($typeEc) || '' === $typeEc) {
            return null;
        }
        $formation = $ec->getFormation();
        $natures = null !== $formation ? ($this->diplomes->configFor($formation)?->structure->natureKeys() ?? []) : [];

        return \in_array($typeEc, $natures, true) ? $typeEc : null;
    }

    public function resolve(TreeNode $ec, string $key): ResolvedValue
    {
        $own = $ec->getAttribute($key);
        if (!\in_array($key, self::KEYS, true) || !$this->isElement($ec)) {
            return new ResolvedValue($own, self::SOURCE_EC);
        }

        $frame = $this->frame($ec, $key);
        if (isset($this->resolving[$frame])) {
            return new ResolvedValue($own, self::SOURCE_EC, false, self::WARN_BOUCLE);
        }
        $this->resolving[$frame] = true;
        try {
            return $this->resolveElement($ec, $key, $own);
        } finally {
            unset($this->resolving[$frame]);
        }
    }

    /** Raccourci : valeur effective ; pour un nœud qui n'est pas le niveau élément, son attribut. */
    public function value(TreeNode $node, string $key): mixed
    {
        return $this->resolve($node, $key)->value;
    }

    /** Fiche de l'EC ; null sans attribut `ficheMatiere` entier (ancien libellé compris) ou si l'id est introuvable. */
    public function fiche(TreeNode $ec): ?FicheMatiere
    {
        $id = $this->ficheId($ec);
        if (null === $id) {
            return null;
        }
        if (!isset($this->fiches[$id])) {
            $fiche = $this->repository->find($id);
            if (null === $fiche) {
                return null;
            }
            $this->fiches[$id] = $fiche;
        }

        return $this->fiches[$id];
    }

    /**
     * Source demandée pour une valeur : celle écrite dans `sources` si elle est valide, sinon la source par
     * défaut. Avant imposition et avant repli : la source effective est celle de resolve().
     */
    public function requestedSource(TreeNode $ec, string $key): string
    {
        if (!\in_array($key, self::KEYS, true) || !$this->isElement($ec)) {
            return self::SOURCE_EC;
        }
        $sources = $ec->getAttribute('sources');
        $written = \is_array($sources) ? ($sources[$key] ?? null) : null;

        return \in_array($written, self::SOURCES, true) ? $written : $this->defaultSource($ec, $key);
    }

    /** Source d'une valeur quand `sources` ne l'écrit pas (§7.2, D6). */
    public function defaultSource(TreeNode $ec, string $key): string
    {
        if (!\in_array($key, self::KEYS, true) || !$this->isElement($ec)) {
            return self::SOURCE_EC;
        }
        if ($this->parentShares($ec, $key)) {
            return self::SOURCE_PARENT;
        }
        if (null === $this->ficheId($ec)) {
            return self::SOURCE_EC;
        }
        $fiche = $this->fiche($ec);
        // fiche introuvable : `fiche` reste la source attendue, le résolveur signale qu'elle ne répond pas
        if (null !== $fiche && 'competencies' === $key && !$this->isInPorteur($fiche, $ec)) {
            return self::SOURCE_EC;
        }

        return self::SOURCE_FICHE;
    }

    /** Premier EC du parcours porteur (ordre de l'arbre) qui utilise la même fiche ; null si l'EC y est lui-même. */
    public function porteurEc(TreeNode $ec): ?TreeNode
    {
        $fiche = $this->fiche($ec);
        if (null === $fiche || $this->isInPorteur($fiche, $ec)) {
            return null;
        }

        return $this->porteurEcs($fiche, $ec)[0] ?? null;
    }

    /** Libellé propre de l'EC s'il est rempli, sinon celui de sa fiche. */
    public function label(TreeNode $ec): string
    {
        if ('' !== trim($ec->getLabel())) {
            return $ec->getLabel();
        }

        return $this->fiche($ec)?->getLibelle() ?? $ec->getLabel();
    }

    public function reset(): void
    {
        $this->fiches = [];
        $this->resolving = [];
        $this->porteurCache = new \WeakMap();
    }

    // ─── interne ───

    private function resolveElement(TreeNode $ec, string $key, mixed $own): ResolvedValue
    {
        $fiche = $this->fiche($ec);
        if (null !== $fiche && \in_array($key, FicheMatiere::IMPOSABLE, true) && $fiche->isImposed($key)) {
            return new ResolvedValue($fiche->getDefault($key), self::SOURCE_FICHE, true);
        }

        return match ($this->requestedSource($ec, $key)) {
            self::SOURCE_FICHE => null !== $fiche
                ? new ResolvedValue($fiche->getDefault($key), self::SOURCE_FICHE)
                : $this->ownValue($own, $this->noFicheWarning($ec)),
            self::SOURCE_PARENT => $this->parentShares($ec, $key)
                ? new ResolvedValue($ec->getParent()?->getAttribute($key), self::SOURCE_PARENT)
                : $this->ownValue($own, self::WARN_IMPOSSIBLE),
            self::SOURCE_PORTEUR => $this->fromPorteur($ec, $fiche, $key, $own),
            default => new ResolvedValue($own, self::SOURCE_EC),
        };
    }

    private function fromPorteur(TreeNode $ec, ?FicheMatiere $fiche, string $key, mixed $own): ResolvedValue
    {
        if (null === $fiche) {
            return $this->ownValue($own, $this->noFicheWarning($ec));
        }
        if ($fiche->isHorsDiplome() || null === $fiche->getFormation() || $this->isInPorteur($fiche, $ec)) {
            return $this->ownValue($own, self::WARN_IMPOSSIBLE);
        }
        $candidates = $this->porteurEcs($fiche, $ec);
        if ([] === $candidates) {
            return $this->ownValue($own, self::WARN_SANS_PORTEUR);
        }

        $porteur = $this->resolve($candidates[0], $key);
        // l'EC porteur est lui-même retombé sur sa valeur propre (boucle ou autre repli) : sa valeur n'est pas
        // une valeur « du porteur », l'EC utilisateur garde la sienne et l'avertissement remonte
        if (self::SOURCE_EC === $porteur->source && null !== $porteur->warning) {
            return $this->ownValue($own, $porteur->warning);
        }

        return new ResolvedValue(
            $porteur->value,
            self::SOURCE_PORTEUR,
            false,
            \count($candidates) > 1 ? self::WARN_PLUSIEURS : null,
        );
    }

    private function ownValue(mixed $own, string $warning): ResolvedValue
    {
        return new ResolvedValue($own, self::SOURCE_EC, false, $warning);
    }

    private function noFicheWarning(TreeNode $ec): string
    {
        return null !== $this->ficheId($ec) ? self::WARN_INTROUVABLE : self::WARN_IMPOSSIBLE;
    }

    /**
     * EC qui utilisent la fiche sous son parcours porteur (toute la structure pédagogique pour un parcours
     * unique), en profondeur d'abord dans l'ordre de l'arbre.
     *
     * @return list<TreeNode>
     */
    private function porteurEcs(FicheMatiere $fiche, TreeNode $ec): array
    {
        $doc = $this->porteurDoc($fiche, $ec);
        if (null === $doc) {
            return [];
        }
        $cache = $this->porteurCache[$doc] ?? null;
        if (null === $cache || $cache['rev'] !== $doc->revision || $cache['size'] !== \count($doc->index)) {
            $cache = ['rev' => $doc->revision, 'size' => \count($doc->index), 'lists' => []];
        }
        $nid = $fiche->getParcoursNid();
        $slot = $fiche->getId().'#'.($nid ?? '');
        if (!isset($cache['lists'][$slot])) {
            $roots = null === $nid ? $doc->pedagogicalRoots() : array_filter([$doc->node($nid)]);
            $found = [];
            $walk = function (TreeNode $node) use (&$walk, &$found, $fiche): void {
                if ($this->isElement($node) && $this->ficheId($node) === $fiche->getId()) {
                    $found[] = $node;
                }
                foreach ($node->getChildren() as $child) {
                    $walk($child);
                }
            };
            foreach ($roots as $root) {
                $walk($root);
            }
            $cache['lists'][$slot] = $found;
        }
        $this->porteurCache[$doc] = $cache;

        return array_values(array_filter($cache['lists'][$slot], static fn (TreeNode $n): bool => $n !== $ec));
    }

    /** Document de la formation porteuse : celui de l'EC quand c'est la même formation (modifications en cours comprises). */
    private function porteurDoc(FicheMatiere $fiche, TreeNode $ec): ?MaquetteDoc
    {
        $formation = $fiche->getFormation();
        if ($fiche->isHorsDiplome() || null === $formation) {
            return null;
        }
        if (null !== $ec->doc && $this->sameFormation($fiche, $ec)) {
            return $ec->doc;
        }

        return ($this->maquette)()->open($formation);
    }

    /** L'EC est-il dans le parcours porteur de sa fiche (comme FicheRelation::Porteur, sans requête) ? */
    private function isInPorteur(FicheMatiere $fiche, TreeNode $ec): bool
    {
        return !$fiche->isHorsDiplome()
            && $this->sameFormation($fiche, $ec)
            && $this->access->parcoursOf($ec)?->nid === $fiche->getParcoursNid();
    }

    private function sameFormation(FicheMatiere $fiche, TreeNode $ec): bool
    {
        $porteuse = $fiche->getFormation();
        $formation = $ec->getFormation();
        if (null === $porteuse || null === $formation) {
            return false;
        }

        return $porteuse === $formation || (null !== $porteuse->getId() && $porteuse->getId() === $formation->getId());
    }

    /** Le parent direct est un bloc de choix qui déclare cette valeur identique pour ses enfants. */
    private function parentShares(TreeNode $ec, string $key): bool
    {
        $parent = $ec->getParent();
        if (null === $parent || !$parent->isChoiceBloc()) {
            return false;
        }
        $identiques = $parent->getAttribute('enfantsIdentiques');

        return \is_array($identiques) && true === ($identiques[$key] ?? false);
    }

    /**
     * Id de fiche de l'attribut `ficheMatiere` ; null pour un ancien libellé (R2) ou une absence.
     * Une chaîne de chiffres vaut aussi un id : les formulaires postent des chaînes.
     */
    public function ficheId(TreeNode $node): ?int
    {
        $raw = $node->getAttribute('ficheMatiere');
        if (\is_int($raw)) {
            return $raw > 0 ? $raw : null;
        }

        return \is_string($raw) && 1 === preg_match('/^[1-9]\d*$/', $raw) ? (int) $raw : null;
    }

    private function isElement(TreeNode $node): bool
    {
        return $node->getType()->isElementLevel();
    }

    private function frame(TreeNode $ec, string $key): string
    {
        $owner = $ec->getFormation()?->getId() ?? 'o'.spl_object_id($ec->doc ?? $ec);

        return $owner.'#'.$ec->nid.'#'.$key;
    }
}
