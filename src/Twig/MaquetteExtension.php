<?php

declare(strict_types=1);

namespace App\Twig;

use App\Controller\FormationController;
use App\Entity\Formation;
use App\Entity\Node;
use App\Entity\NodeType;
use App\Maquette\AttributeCatalog;
use App\Repository\NodeRepository;
use App\Repository\NodeTypeRepository;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class MaquetteExtension extends AbstractExtension
{
    public function __construct(
        private readonly NodeTypeRepository $types,
        private readonly NodeRepository $nodes,
        private readonly AttributeCatalog $catalog,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('types_allowed_for', $this->typesAllowedFor(...)),
            new TwigFunction('valid_parents', $this->validParents(...)),
            new TwigFunction('can_be_root', $this->canBeRoot(...)),
            new TwigFunction('parcours_candidates', $this->parcoursCandidates(...)),
            new TwigFunction('structure_rows', $this->structureRows(...)),
            new TwigFunction('structure_addable', $this->structureAddable(...)),
            new TwigFunction('root_type', $this->rootType(...)),
            new TwigFunction('type_meta', $this->typeMeta(...)),
            new TwigFunction('all_node_types', fn () => $this->types->findAllOrdered()),
            new TwigFunction('node_path', $this->nodePath(...)),
            new TwigFunction('capability_labels', $this->capabilityLabels(...)),
            new TwigFunction('param_nav', $this->paramNav(...)),
            new TwigFunction('period_unit', $this->periodUnit(...)),
            new TwigFunction('param_sections', static fn () => FormationController::PARAM_SECTIONS),
            new TwigFunction('param_status', static fn (Formation $f, string $k) => FormationController::paramStatus($f, $k)),
        ];
    }

    /**
     * Type ajoutable sous ce nœud : uniquement l'enfant prévu par le squelette
     * de la formation (0 ou 1 type). La hiérarchie ne vient plus du type.
     *
     * @return list<NodeType>
     */
    public function typesAllowedFor(Node $node): array
    {
        $childKey = $node->getFormation()?->getChildTypeKey($node->getType()->getKey());
        if ($childKey === null) {
            return [];
        }
        $type = $this->types->findAllIndexed()[$childKey] ?? null;

        return $type !== null ? [$type] : [];
    }

    /**
     * Lignes du tableau « Configuration de la structure » : le squelette de la
     * formation, niveau parcours inclus (fixe) quand elle est multi-parcours.
     *
     * @return list<array{key: string, type: ?NodeType, fixed: bool, mono: bool}>
     */
    public function structureRows(Formation $formation): array
    {
        $byKey = $this->types->findAllIndexed();
        $rows = [];

        foreach ($formation->getEffectiveStructure() as $i => $key) {
            $isRootParcours = 0 === $i && 'parcours' === $key;
            $rows[] = [
                'key' => $key,
                'type' => $byKey[$key] ?? null,
                'fixed' => $isRootParcours,
                'mono' => $isRootParcours && $formation->isMono(),
            ];
        }

        return $rows;
    }

    /**
     * Types que l'on peut ajouter au bout du squelette : n'importe quel type
     * pas déjà dans la chaîne et hors « parcours » (implicite en multi-parcours).
     *
     * @return list<NodeType>
     */
    public function structureAddable(Formation $formation): array
    {
        $inChain = array_flip($formation->getEffectiveStructure());

        return array_values(array_filter(
            $this->types->findAllOrdered(),
            static fn (NodeType $t) => 'parcours' !== $t->getKey() && !isset($inChain[$t->getKey()]),
        ));
    }

    /** Type de la racine VISIBLE de l'arbre, ou null si le squelette est vide. */
    public function rootType(Formation $formation): ?NodeType
    {
        $key = $formation->getVisibleRootTypeKey();

        return null !== $key ? ($this->types->findAllIndexed()[$key] ?? null) : null;
    }

    /**
     * Libellé + icône de chaque type, indexés par clé — pour l'étiquetage
     * côté JS (bouton « Ajouter » de l'arbre).
     *
     * @return array<string, array{label: string, icon: string}>
     */
    public function typeMeta(): array
    {
        return array_map(
            static fn (NodeType $t) => ['label' => $t->getLabel(), 'icon' => $t->getIcon() ?? ''],
            $this->types->findAllIndexed(),
        );
    }

    /**
     * Parents valides pour ce nœud : les nœuds de la formation dont le type est,
     * dans le squelette, le parent du type de ce nœud — hors lui-même et sa
     * descendance. Vide si ce type est la racine (→ seul « racine » possible).
     *
     * @return list<Node>
     */
    public function validParents(Node $node): array
    {
        $parentKey = $node->getFormation()?->getParentTypeKey($node->getType()->getKey());
        if ($parentKey === null) {
            return [];
        }

        $descendants = [];
        $collect = static function (Node $n) use (&$collect, &$descendants): void {
            $descendants[$n->getId()] = true;
            foreach ($n->getChildren() as $c) {
                $collect($c);
            }
        };
        $collect($node);

        return array_values(array_filter(
            $this->nodes->findForFormation($node->getFormation()),
            static fn (Node $cand) => !isset($descendants[$cand->getId()])
                && $cand->getType()->getKey() === $parentKey,
        ));
    }

    /** Ce nœud peut-il être rattaché à la racine de la formation ? */
    public function canBeRoot(Node $node): bool
    {
        return (bool) $node->getFormation()?->canBeRootType($node->getType()->getKey());
    }

    /**
     * Parcours pouvant servir de parent (ramification) : autres parcours de la
     * formation, hors lui-même et hors sa propre descendance parcours (anti-cycle).
     *
     * @return list<Node>
     */
    public function parcoursCandidates(Node $node): array
    {
        $forbidden = [$node->getId() => true];
        // descendance parcours de $node
        $all = array_filter($this->nodes->findForFormation($node->getFormation()), static fn (Node $n) => $n->isParcours());
        $changed = true;
        while ($changed) {
            $changed = false;
            foreach ($all as $p) {
                $pp = $p->getParcoursParent()?->getId();
                if ($pp !== null && isset($forbidden[$pp]) && !isset($forbidden[$p->getId()])) {
                    $forbidden[$p->getId()] = true;
                    $changed = true;
                }
            }
        }

        return array_values(array_filter(
            $all,
            fn (Node $p) => !isset($forbidden[$p->getId()])
                && Node::parcoursPeriodsAllowChild($p->getPeriodeDebut(), $p->getPeriodeFin(), $node->getPeriodeDebut(), $node->getPeriodeFin()),
        ));
    }

    /**
     * Libellé de l'unité de temps de la formation : override explicite, sinon
     * le libellé du type du 1er niveau du squelette, sinon « Période ».
     */
    public function periodUnit(Formation $formation): string
    {
        if ($formation->getCalendarUnit()) {
            return $formation->getCalendarUnit();
        }
        $key = $formation->getChildTypeKey('parcours');
        $type = $key !== null ? ($this->types->findAllIndexed()[$key] ?? null) : null;

        return $type?->getLabel() ?? 'Période';
    }

    /** @return list<Node> du racine jusqu'au nœud. */
    public function nodePath(Node $node): array
    {
        $path = [];
        $cursor = $node;
        while ($cursor !== null) {
            array_unshift($path, $cursor);
            $cursor = $cursor->getParent();
        }

        return $path;
    }

    /** @return array<string, string> */
    public function capabilityLabels(): array
    {
        $out = [];
        foreach ($this->catalog->all() as $key => $def) {
            $out[$key] = $def['label'];
        }
        foreach (AttributeCatalog::FLAGS as $key => $label) {
            $out[$key] = $label;
        }

        return $out;
    }

    /**
     * Navigation rapide entre les « Paramètre du nœud » : pour chaque type du
     * squelette, un nœud représentatif (le nœud courant, sinon un ancêtre de ce
     * type, sinon le premier nœud de ce type dans la formation).
     *
     * @return list<array{type: NodeType, target: Node, current: bool}>
     */
    public function paramNav(Node $node): array
    {
        $formation = $node->getFormation();
        $byKey = $this->types->findAllIndexed();
        $all = $this->nodes->findForFormation($formation);

        $ancestors = [];
        for ($c = $node->getParent(); $c !== null; $c = $c->getParent()) {
            $ancestors[$c->getType()->getKey()] ??= $c;
        }

        $out = [];
        foreach ($formation->getEffectiveStructure() as $key) {
            $type = $byKey[$key] ?? null;
            if ($type === null) {
                continue;
            }
            $target = $key === $node->getType()->getKey() ? $node : ($ancestors[$key] ?? null);
            if ($target === null) {
                foreach ($all as $n) {
                    if ($n->getType()->getKey() === $key) {
                        $target = $n;
                        break;
                    }
                }
            }
            if ($target !== null) {
                $out[] = ['type' => $type, 'target' => $target, 'current' => $target === $node];
            }
        }

        return $out;
    }
}
