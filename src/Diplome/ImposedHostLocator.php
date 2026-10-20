<?php

declare(strict_types=1);

namespace App\Diplome;

use App\Diplome\Spec\ImposedElement;
use App\Maquette\Doc\MaquetteDoc;
use App\Maquette\Doc\TreeNode;

/**
 * Nœuds qui doivent accueillir un élément imposé : parmi tous les nœuds de son
 * `parentLevel`, dans l'ordre de l'arbre (et par parcours en multi-parcours),
 * ceux désignés par `position` (`first`, `last`, `all` ou un indice « n » 1-based).
 */
final class ImposedHostLocator
{
    /**
     * @param list<TreeNode> $candidates nœuds du niveau parent, dans l'ordre de l'arbre
     *
     * @return list<TreeNode>
     */
    public function select(array $candidates, string $position): array
    {
        return match (true) {
            'all' === $position => $candidates,
            'first' === $position => \array_slice($candidates, 0, 1),
            'last' === $position => \array_slice($candidates, -1),
            ctype_digit($position) => \array_slice($candidates, (int) $position - 1, 1),
            default => [],
        };
    }

    /** @return list<TreeNode> hôtes de l'élément imposé dans une maquette existante */
    public function hostsIn(MaquetteDoc $doc, ImposedElement $imposed): array
    {
        $parcours = $doc->parcoursNodes();
        $scopes = [] === $parcours ? [$doc->pedagogicalRoots()] : array_map(static fn (TreeNode $p): array => $p->getChildren(), $parcours);

        $hosts = [];
        foreach ($scopes as $roots) {
            $candidates = [];
            $this->collect($roots, $imposed->parentLevel, $candidates);
            array_push($hosts, ...$this->select($candidates, $imposed->position));
        }

        return $hosts;
    }

    /**
     * @param list<TreeNode> $nodes
     * @param list<TreeNode> $out
     */
    private function collect(array $nodes, string $typeKey, array &$out): void
    {
        foreach ($nodes as $node) {
            if ($node->typeKey === $typeKey) {
                $out[] = $node;
            }
            $this->collect($node->getChildren(), $typeKey, $out);
        }
    }
}
