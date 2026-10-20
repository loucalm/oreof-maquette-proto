<?php

declare(strict_types=1);

namespace App\Diplome\Rule\Template;

use App\Diplome\Rule\CompiledRule;
use App\Diplome\Rule\RuleParam;
use App\Diplome\Rule\RuleTemplateInterface;

/**
 * Part d'un attribut portée par les feuilles d'une nature donnée, rapportée au
 * total de l'attribut sur toutes les feuilles du parent (ex. SAE >= 40 % des ECTS).
 */
final class RatioRule implements RuleTemplateInterface
{
    public function key(): string
    {
        return 'ratio';
    }

    public function label(): string
    {
        return "Part d'un attribut pour une nature d'élément";
    }

    public function params(): array
    {
        return [
            new RuleParam('attribute', 'Attribut', 'attribute'),
            new RuleParam('numeratorNature', "Nature d'élément", 'nature'),
            new RuleParam('op', 'Comparaison', 'op'),
            new RuleParam('percent', 'Pourcentage', 'int'),
        ];
    }

    public function compile(array $params): array
    {
        $attribute = (string) $params['attribute'];
        $op = (string) $params['op'];
        $percent = (float) $params['percent'];

        $node = [
            'kind' => 'comparison',
            'op' => $op,
            'left' => [
                'kind' => 'ratio',
                'numerator' => ['kind' => 'aggregate', 'fn' => 'SUM', 'collection' => 'leaves', 'field' => $attribute, 'where' => ['field' => 'nature', 'op' => '==', 'value' => (string) $params['numeratorNature']]],
                'denominator' => ['kind' => 'aggregate', 'fn' => 'SUM', 'collection' => 'leaves', 'field' => $attribute],
            ],
            'right' => ['kind' => 'literal', 'value' => $percent / 100],
        ];

        return [new CompiledRule($node, true, \sprintf('Part de « %s » de nature %s %s %s %%', $attribute, $params['numeratorNature'], $op, rtrim(rtrim(number_format($percent, 2, ',', ''), '0'), ',')))];
    }
}
