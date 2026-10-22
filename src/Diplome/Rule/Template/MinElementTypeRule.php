<?php

declare(strict_types=1);

namespace App\Diplome\Rule\Template;

use App\Diplome\Rule\CompiledRule;
use App\Diplome\Rule\RuleParam;
use App\Diplome\Rule\RuleTemplateInterface;

/** Au moins N éléments (feuilles) du type d'EC donné sous le nœud de portée. */
final class MinElementTypeRule implements RuleTemplateInterface
{
    public function key(): string
    {
        return 'min_element_type';
    }

    public function label(): string
    {
        return "Au moins N EC d'un type";
    }

    public function params(): array
    {
        return [
            new RuleParam('nature', "Type d'EC", 'nature'),
            new RuleParam('min', "Nombre minimal d'EC", 'int', false),
        ];
    }

    public function compile(array $params): array
    {
        $nature = (string) $params['nature'];
        $min = max(1, (int) ($params['min'] ?? 1));
        $node = [
            'kind' => 'comparison',
            'op' => '>=',
            'left' => ['kind' => 'aggregate', 'fn' => 'COUNT', 'collection' => 'leaves', 'where' => [['field' => 'nature', 'op' => '==', 'value' => $nature]]],
            'right' => ['kind' => 'literal', 'value' => $min],
        ];

        return [new CompiledRule($node, true, \sprintf('Au moins %d EC de type « %s »', $min, $nature), 'min')];
    }
}
