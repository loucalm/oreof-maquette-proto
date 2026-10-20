<?php

declare(strict_types=1);

namespace App\Diplome;

use App\Diplome\Check\StructureContextBuilder;
use App\Diplome\Spec\ImposedElement;
use App\Maquette\Doc\MaquetteDoc;
use App\Maquette\Doc\TreeNode;

/**
 * Où se trouve, et où placer, un élément imposé.
 *
 * Portée : chaque cursus (le parcours, ou en ramification chaque parcours feuille avec ses parcours
 * parents) ; toute la maquette en mono-parcours. L'élément est « présent » s'il existe dans sa portée
 * un nœud marqué `_imposed` = son uid, où qu'il soit : sa `position` ne sert qu'à le placer.
 *
 * Placement : parmi tous les nœuds de son `parentLevel` dans la portée, dans l'ordre de l'arbre, ceux
 * désignés par `position` (`first`, `last`, `all` ou un indice « n » 1-based).
 */
final class ImposedHostLocator
{
    public function __construct(private readonly StructureContextBuilder $contexts)
    {
    }

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

    /** @return list<?TreeNode> portées des éléments imposés (null = racine, en mono-parcours) */
    public function scopes(MaquetteDoc $doc): array
    {
        return $this->contexts->cursusScopes($doc);
    }

    /** @return list<TreeNode> nœuds de l'élément imposé présents dans la portée */
    public function presentIn(MaquetteDoc $doc, ?TreeNode $scope, ImposedElement $imposed): array
    {
        return array_values(array_filter(
            $this->contexts->cursusNodes($doc, $scope),
            static fn (TreeNode $n): bool => $n->getAttribute('_imposed') === $imposed->uid,
        ));
    }

    /** @return list<TreeNode> hôtes désignés par la position, où ajouter l'élément quand il est absent de la portée */
    public function placementHostsIn(MaquetteDoc $doc, ?TreeNode $scope, ImposedElement $imposed): array
    {
        $candidates = array_values(array_filter(
            $this->contexts->cursusNodes($doc, $scope),
            static fn (TreeNode $n): bool => $n->typeKey === $imposed->parentLevel,
        ));

        return $this->select($candidates, $imposed->position);
    }

    /**
     * Hôtes effectifs de l'élément (pour la surcharge de borne) : dans chaque portée, le parent du nœud
     * imposé présent ; à défaut, l'hôte désigné par la position.
     *
     * @return list<TreeNode>
     */
    public function hostsIn(MaquetteDoc $doc, ImposedElement $imposed): array
    {
        $hosts = [];
        foreach ($this->scopes($doc) as $scope) {
            $present = $this->presentIn($doc, $scope, $imposed);
            $scopeHosts = [] !== $present
                ? array_filter(array_map(static fn (TreeNode $n): ?TreeNode => $n->getParent(), $present))
                : $this->placementHostsIn($doc, $scope, $imposed);
            foreach ($scopeHosts as $host) {
                $hosts[$host->nid] = $host; // un portail partagé par plusieurs cursus n'est compté qu'une fois
            }
        }

        return array_values($hosts);
    }

    /** Crée le nœud imposé sous son hôte : libellé, verrous, marque `_imposed` et nature d'élément. */
    public static function addTo(MaquetteDoc $doc, TreeNode $host, ImposedElement $imposed): TreeNode
    {
        $node = $doc->addNode($host->nid, $imposed->typeKey, $imposed->label);
        $node->locked = $imposed->locked;
        $node->setAttribute('_imposed', $imposed->uid);
        $node->setAttribute('natureElement', $imposed->nature);

        return $node;
    }
}
