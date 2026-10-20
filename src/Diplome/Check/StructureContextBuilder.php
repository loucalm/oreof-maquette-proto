<?php

declare(strict_types=1);

namespace App\Diplome\Check;

use App\Maquette\Doc\MaquetteDoc;
use App\Maquette\Doc\TreeNode;

/**
 * Construit le contexte de RuleEvaluator pour un parent de la maquette.
 *
 * Collections : `children` (enfants directs ; un bloc à choix compte pour 1,
 * avec ses propres ECTS) et `leaves` (feuilles descendantes ; dans un bloc à
 * choix un seul enfant, le premier, est compté).
 * Colonnes : nid, typeKey, hostTypeKey (type réel de l'élément ; pour un bloc à
 * choix, celui de ses enfants), nature (attribut `natureElement`), ects, plus
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
     * Contexte d'un cursus : ses périodes propres plus celles de ses parcours parents (le portail compris).
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function cursusContextFor(MaquetteDoc $doc, ?TreeNode $leaf): array
    {
        $context = $this->contextFor($doc, $leaf);
        $seen = [($leaf?->nid ?? '') => true];
        for ($p = $leaf?->getParcoursParent(); null !== $p && !isset($seen[$p->nid]); $p = $p->getParcoursParent()) {
            $seen[$p->nid] = true;
            $own = $this->contextFor($doc, $p);
            $context['children'] = [...$context['children'], ...$own['children']];
            $context['leaves'] = [...$context['leaves'], ...$own['leaves']];
        }

        return $context;
    }

    /** @return array<string, list<array<string, mixed>>> */
    public function contextFor(MaquetteDoc $doc, ?TreeNode $scope): array
    {
        $children = $this->structural($scope?->getChildren() ?? $doc->roots);
        $leaves = [];
        foreach ($children as $child) {
            $this->collectLeaves($child, $leaves);
        }

        return [
            'children' => array_map($this->row(...), $children),
            'leaves' => array_map($this->row(...), $leaves),
        ];
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
    private function row(TreeNode $node): array
    {
        return array_merge($node->getAttributes(), [
            'nid' => $node->getId(),
            'typeKey' => $node->typeKey,
            'hostTypeKey' => $this->hostTypeKey($node),
            'nature' => $node->getAttribute('natureElement'),
            'ects' => $node->getAttribute('ects'),
        ]);
    }
}
