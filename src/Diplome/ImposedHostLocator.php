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
 * parents) ; toute la maquette en mono-parcours. En `first`, `last` ou « n », l'élément est « présent »
 * s'il existe dans sa portée un nœud marqué `_imposed` = son uid, où qu'il soit : sa position ne sert
 * qu'à le placer. En `all`, il est attendu sous CHAQUE nœud de son `parentLevel` (un hôte qui en est
 * privé est en défaut, même si l'élément figure ailleurs dans la portée).
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

    /** @return list<TreeNode> nœuds du niveau parent de l'élément dans la portée, dans l'ordre de l'arbre */
    public function levelNodesIn(MaquetteDoc $doc, ?TreeNode $scope, ImposedElement $imposed): array
    {
        return array_values(array_filter(
            $this->contexts->cursusNodes($doc, $scope),
            static fn (TreeNode $n): bool => $n->typeKey === $imposed->parentLevel,
        ));
    }

    /** @return list<TreeNode> hôtes désignés par la position, où ajouter l'élément quand il est absent de la portée */
    public function placementHostsIn(MaquetteDoc $doc, ?TreeNode $scope, ImposedElement $imposed): array
    {
        return $this->select($this->levelNodesIn($doc, $scope, $imposed), $imposed->position);
    }

    /**
     * Hôtes où l'élément manque et doit être ajouté : en `all`, chaque nœud du niveau parent sans
     * descendant `_imposed` = uid ; sinon l'hôte désigné par la position, si l'élément est absent de la portée.
     *
     * @return list<TreeNode>
     */
    public function missingHostsIn(MaquetteDoc $doc, ?TreeNode $scope, ImposedElement $imposed): array
    {
        if ($imposed->isEveryHost()) {
            return array_values(array_filter(
                $this->levelNodesIn($doc, $scope, $imposed),
                static fn (TreeNode $host): bool => !self::holds($host, $imposed),
            ));
        }

        return [] === $this->presentIn($doc, $scope, $imposed) ? $this->placementHostsIn($doc, $scope, $imposed) : [];
    }

    /** L'hôte a-t-il, parmi ses descendants, le nœud de l'élément imposé ? */
    public static function holds(TreeNode $host, ImposedElement $imposed): bool
    {
        foreach ($host->getChildren() as $child) {
            if ($child->getAttribute('_imposed') === $imposed->uid || self::holds($child, $imposed)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Hôtes effectifs de l'élément (pour la surcharge de borne) : dans chaque portée, le parent de chaque
     * nœud imposé présent, plus les hôtes où il manque (cf. missingHostsIn).
     *
     * @return list<TreeNode>
     */
    public function hostsIn(MaquetteDoc $doc, ImposedElement $imposed): array
    {
        $hosts = [];
        foreach ($this->scopes($doc) as $scope) {
            $scopeHosts = [
                ...array_filter(array_map(static fn (TreeNode $n): ?TreeNode => $n->getParent(), $this->presentIn($doc, $scope, $imposed))),
                ...$this->missingHostsIn($doc, $scope, $imposed),
            ];
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
