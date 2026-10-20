<?php

declare(strict_types=1);

namespace App\Diplome\Rule\Template;

use App\Diplome\Rule\CompiledRule;
use App\Diplome\Rule\RuleParam;
use App\Diplome\Rule\RuleTemplateInterface;

/** Un élément du type (et de la nature) donnés est présent parmi les enfants du parent. */
final class RequiredElementRule implements RuleTemplateInterface
{
    public function key(): string
    {
        return 'required_element';
    }

    public function label(): string
    {
        return 'Élément imposé';
    }

    public function params(): array
    {
        return [
            new RuleParam('typeKey', "Type d'élément", 'typeKey'),
            new RuleParam('nature', "Nature d'élément", 'nature', false),
        ];
    }

    public function compile(array $params): array
    {
        $where = [['field' => 'hostTypeKey', 'op' => '==', 'value' => (string) $params['typeKey']]];
        $nature = (string) ($params['nature'] ?? '');
        if ('' !== $nature) {
            $where[] = ['field' => 'nature', 'op' => '==', 'value' => $nature];
        }
        $node = [
            'kind' => 'comparison',
            'op' => '>=',
            'left' => ['kind' => 'aggregate', 'fn' => 'COUNT', 'collection' => 'children', 'where' => $where],
            'right' => ['kind' => 'literal', 'value' => 1],
        ];

        return [new CompiledRule($node, true, \sprintf('Élément imposé : %s%s', $params['typeKey'], '' !== $nature ? " ($nature)" : ''))];
    }
}
