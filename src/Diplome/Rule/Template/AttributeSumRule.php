<?php

declare(strict_types=1);

namespace App\Diplome\Rule\Template;

use App\Diplome\Rule\RuleParam;

/** Somme d'un attribut (ex. ECTS) sur les enfants d'un parent. */
final class AttributeSumRule extends AbstractBoundedRule
{
    public function key(): string
    {
        return 'attribute_sum';
    }

    public function label(): string
    {
        return "Somme d'un attribut sur les enfants";
    }

    public function params(): array
    {
        return [
            new RuleParam('attribute', 'Attribut', 'attribute'),
            new RuleParam('min', 'Minimum', 'int', false),
            new RuleParam('max', 'Maximum', 'int', false),
            new RuleParam('childType', "Type d'élément compté", 'typeKey', false),
        ];
    }

    protected function boundNode(string $op, float $bound, array $params): array
    {
        return [
            'kind' => 'comparison',
            'op' => $op,
            'left' => ['kind' => 'aggregate', 'fn' => 'SUM', 'collection' => 'children', 'field' => (string) $params['attribute']] + self::childrenOfType($params),
            'right' => ['kind' => 'literal', 'value' => $bound],
        ];
    }

    protected function message(string $words, float $bound, array $params): string
    {
        return \sprintf('Somme de « %s » : %s %s', $params['attribute'], $words, self::num($bound));
    }
}
