<?php

declare(strict_types=1);

namespace App\Diplome\Rule\Template;

use App\Diplome\Rule\CompiledRule;
use App\Diplome\Rule\RuleParam;
use App\Diplome\Rule\RuleTemplateInterface;

/** Tous les enfants portent la même valeur d'un attribut (ex. ECTS égaux dans un bloc à choix). */
final class SameChildValueRule implements RuleTemplateInterface
{
    public function key(): string
    {
        return 'same_child_value';
    }

    public function label(): string
    {
        return "Même valeur d'un attribut pour tous les enfants";
    }

    public function params(): array
    {
        return [new RuleParam('attribute', 'Attribut', 'attribute')];
    }

    public function compile(array $params): array
    {
        $attribute = (string) $params['attribute'];
        $node = [
            'kind' => 'comparison',
            'op' => '==',
            'left' => ['kind' => 'aggregate', 'fn' => 'MAX', 'collection' => 'children', 'field' => $attribute],
            'right' => ['kind' => 'aggregate', 'fn' => 'MIN', 'collection' => 'children', 'field' => $attribute],
        ];

        return [new CompiledRule($node, true, \sprintf('Tous les éléments ont la même valeur de « %s »', $attribute))];
    }
}
