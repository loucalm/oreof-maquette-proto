<?php

declare(strict_types=1);

namespace App\Diplome\Check;

use App\Diplome\Spec\VersionConfig;
use App\Maquette\Doc\MaquetteDoc;
use App\Maquette\Doc\TreeNode;

/**
 * Construit le contexte de RuleEvaluator pour un parent de la maquette.
 *
 * Collections : `children` (enfants directs ; un bloc à choix compte pour 1,
 * avec ses propres ECTS) et `leaves` (feuilles descendantes ; dans un bloc à
 * choix un seul enfant, le premier, est compté).
 * Colonnes : nid, typeKey, hostTypeKey (type réel de l'élément ; pour un bloc à
 * choix, celui de ses enfants), nature (cf. VersionConfig::natureOf quand la
 * configuration est fournie, sinon l'attribut `natureElement` brut), ects, plus
 * les attributs du nœud.
 */
final class StructureContextBuilder
{
    /** @return list<?TreeNode> parcours en multi-parcours, [null] (racine) en mono-parcours */
    public function rootScopes(MaquetteDoc $doc): array
    {
        $parcours = $doc->parcoursNodes();

        return [] === $parcours ? [null] : $parcours;
    }

    /**
     * Portées de la durée : un cursus par parcours FEUILLE de la ramification (un parcours qui a des
     * sous-parcours n'est pas mesuré seul) ; sans ramification, comme rootScopes().
     *
     * @return list<?TreeNode>
     */
    public function cursusScopes(MaquetteDoc $doc): array
    {
        $parcours = $doc->parcoursNodes();
        if ([] === $parcours) {
            return [null];
        }
        $parents = [];
        foreach ($parcours as $p) {
            $parent = $p->getParcoursParent();
            if (null !== $parent) {
                $parents[$parent->nid] = true;
            }
        }

        return array_values(array_filter($parcours, static fn (TreeNode $p): bool => !isset($parents[$p->nid])));
    }

    /**
     * Tous les nœuds structurels d'un cursus, dans l'ordre de l'arbre : ceux des parcours parents
     * (portail d'abord), puis ceux du parcours feuille ; toute la maquette en mono-parcours.
     *
     * @return list<TreeNode>
     */
    public function cursusNodes(MaquetteDoc $doc, ?TreeNode $leaf): array
    {
        $nodes = [];
        foreach (array_reverse($this->cursusChain($leaf)) as $scope) {
            foreach ($this->structural($scope?->getChildren() ?? $doc->pedagogicalRoots()) as $child) {
                $this->collectAll($child, $nodes);
            }
        }

        return $nodes;
    }

    /**
     * Contexte de présence d'un cursus : `children` = tous ses nœuds structurels (pas seulement les
     * enfants directs), pour savoir si un élément y figure quelle que soit sa position.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function cursusPresenceContextFor(MaquetteDoc $doc, ?TreeNode $leaf, ?VersionConfig $config = null): array
    {
        return [
            'children' => array_map(fn (TreeNode $n): array => $this->row($n, $config), $this->cursusNodes($doc, $leaf)),
            'leaves' => [],
        ];
    }

    /**
     * Contexte de présence sous un hôte : `children` = tous ses nœuds structurels descendants.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function hostPresenceContextFor(TreeNode $host, ?VersionConfig $config = null): array
    {
        $nodes = [];
        foreach ($this->structural($host->getChildren()) as $child) {
            $this->collectAll($child, $nodes);
        }

        return ['children' => array_map(fn (TreeNode $n): array => $this->row($n, $config), $nodes), 'leaves' => []];
    }

    /** @return list<?TreeNode> le parcours feuille puis ses parcours parents ([null] en mono-parcours) */
    private function cursusChain(?TreeNode $leaf): array
    {
        $chain = [$leaf];
        $seen = [($leaf?->nid ?? '') => true];
        for ($p = $leaf?->getParcoursParent(); null !== $p && !isset($seen[$p->nid]); $p = $p->getParcoursParent()) {
            $seen[$p->nid] = true;
            $chain[] = $p;
        }

        return $chain;
    }

    /** @return array<string, list<array<string, mixed>>> */
    public function contextFor(MaquetteDoc $doc, ?TreeNode $scope, ?VersionConfig $config = null): array
    {
        $children = $this->structural($scope?->getChildren() ?? $doc->roots);
        $leaves = [];
        foreach ($children as $child) {
            $this->collectLeaves($child, $leaves);
        }
        $row = fn (TreeNode $n): array => $this->row($n, $config);

        return [
            'children' => array_map($row, $children),
            'leaves' => array_map($row, $leaves),
        ];
    }

    /** @param list<TreeNode> $out */
    private function collectAll(TreeNode $node, array &$out): void
    {
        $out[] = $node;
        foreach ($this->structural($node->getChildren()) as $child) {
            $this->collectAll($child, $out);
        }
    }

    /**
     * @param list<TreeNode> $nodes
     *
     * @return list<TreeNode> sans les blocs de compétences, qui ne font pas partie de la structure pédagogique
     */
    private function structural(array $nodes): array
    {
        return array_values(array_filter($nodes, static fn (TreeNode $n): bool => !$n->isBloc()));
    }

    /** @param list<TreeNode> $leaves */
    private function collectLeaves(TreeNode $node, array &$leaves): void
    {
        $children = $this->structural($node->getChildren());
        if ([] === $children) {
            if (!$node->isChoiceBloc()) {
                $leaves[] = $node;
            }

            return;
        }
        foreach ($node->isChoiceBloc() ? [$children[0]] : $children as $child) {
            $this->collectLeaves($child, $leaves);
        }
    }

    private function hostTypeKey(TreeNode $node): string
    {
        while ($node->isChoiceBloc()) {
            $first = $this->structural($node->getChildren())[0] ?? null;
            if (null === $first) {
                break;
            }
            $node = $first;
        }

        return $node->typeKey;
    }

    /** @return array<string, mixed> */
    private function row(TreeNode $node, ?VersionConfig $config): array
    {
        return array_merge($node->getAttributes(), [
            'nid' => $node->getId(),
            'typeKey' => $node->typeKey,
            'hostTypeKey' => $this->hostTypeKey($node),
            'nature' => null !== $config ? $config->natureOf($node) : $node->getAttribute('natureElement'),
            'ects' => $node->getAttribute('ects'),
        ]);
    }
}
