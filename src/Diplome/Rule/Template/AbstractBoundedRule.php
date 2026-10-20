<?php

declare(strict_types=1);

namespace App\Diplome\Rule\Template;

use App\Diplome\Rule\CompiledRule;
use App\Diplome\Rule\RuleTemplateInterface;

/**
 * Base des gabarits à bornes min / max : produit la partie minimum (non
 * bloquante) et la partie maximum (bloquante) quand la borne est renseignée.
 */
abstract class AbstractBoundedRule implements RuleTemplateInterface
{
    /**
     * @param array<string, mixed> $params
     *
     * @return list<CompiledRule>
     */
    public function compile(array $params): array
    {
        $rules = [];
        foreach (['min' => ['>=', false, 'au moins'], 'max' => ['<=', true, 'au plus']] as $part => [$op, $blocking, $words]) {
            if (!isset($params[$part]) || '' === $params[$part]) {
                continue;
            }
            $bound = (float) $params[$part];
            $rules[] = new CompiledRule($this->boundNode($op, $bound, $params), $blocking, $this->message($words, $bound, $params), $part);
        }

        return $rules;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    abstract protected function boundNode(string $op, float $bound, array $params): array;

    /** @param array<string, mixed> $params */
    abstract protected function message(string $words, float $bound, array $params): string;

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    protected static function childrenOfType(array $params): array
    {
        $type = (string) ($params['childType'] ?? '');

        return '' === $type ? [] : ['where' => ['field' => 'hostTypeKey', 'op' => '==', 'value' => $type]];
    }

    protected static function num(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, ',', ''), '0'), ',');
    }
}
