<?php

declare(strict_types=1);

namespace App\Diplome\Rule\Template;

use App\Diplome\Rule\RuleParam;

/** Valeur d'un attribut de chaque enfant comprise entre min et max. */
final class AttributeValueRule extends AbstractBoundedRule
{
    public function key(): string
    {
        return 'attribute_value';
    }

    public function label(): string
    {
        return "Valeur d'un attribut de chaque enfant";
    }

    public function params(): array
    {
        return [
            new RuleParam('attribute', 'Attribut', 'attribute'),
            new RuleParam('min', 'Minimum', 'int', false),
            new RuleParam('max', 'Maximum', 'int', false),
        ];
    }

    protected function boundNode(string $op, float $bound, array $params): array
    {
        return [
            'kind' => 'each',
            'collection' => 'children',
            'field' => (string) $params['attribute'],
            'op' => $op,
            'value' => ['kind' => 'literal', 'value' => $bound],
        ];
    }

    protected function message(string $words, float $bound, array $params): string
    {
        return \sprintf('Valeur de « %s » de chaque élément : %s %s', $params['attribute'], $words, self::num($bound));
    }
}
