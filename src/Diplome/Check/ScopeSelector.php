<?php

declare(strict_types=1);

namespace App\Diplome\Check;

use App\Maquette\Doc\TreeNode;

/**
 * Désigne, parmi les nœuds d'un niveau dans un cursus, ceux que vise la portée positionnelle d'une règle :
 * tous, le premier, le dernier, ou le numéro affiché (après `startAt` : en LP, « S6 » est n = 6).
 */
final class ScopeSelector
{
    public const ALL = 'all';
    public const FIRST = 'first';
    public const LAST = 'last';

    /**
     * @param list<TreeNode> $nodes nœuds du niveau dans un cursus, ordre de l'arbre
     *
     * @return list<TreeNode>
     */
    public function select(array $nodes, string $position, int $startAt): array
    {
        return match (true) {
            self::ALL === $position => $nodes,
            self::FIRST === $position => \array_slice($nodes, 0, 1),
            self::LAST === $position => \array_slice($nodes, -1),
            ctype_digit($position) => ($index = (int) $position - $startAt) >= 0 ? \array_slice($nodes, $index, 1) : [],
            default => [],
        };
    }
}
