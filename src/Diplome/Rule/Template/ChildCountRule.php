<?php

declare(strict_types=1);

namespace App\Diplome\Rule\Template;

use App\Diplome\Rule\RuleParam;

/** Nombre d'enfants (d'un type donné) par parent ; un bloc à choix compte pour 1. */
final class ChildCountRule extends AbstractBoundedRule
{
    public function key(): string
    {
        return 'child_count';
    }

    public function label(): string
    {
        return "Nombre d'éléments enfants";
    }

    public function params(): array
    {
        return [
            new RuleParam('childType', "Type d'élément compté", 'typeKey', false),
            new RuleParam('min', 'Minimum', 'int', false),
            new RuleParam('max', 'Maximum', 'int', false),
        ];
    }

    protected function boundNode(string $op, float $bound, array $params): array
    {
        return [
            'kind' => 'comparison',
            'op' => $op,
            'left' => ['kind' => 'aggregate', 'fn' => 'COUNT', 'collection' => 'children'] + self::childrenOfType($params),
            'right' => ['kind' => 'literal', 'value' => $bound],
        ];
    }

    protected function message(string $words, float $bound, array $params): string
    {
        return \sprintf('Nombre d\'éléments : %s %s', $words, self::num($bound));
    }
}
