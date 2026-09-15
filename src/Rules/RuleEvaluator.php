<?php

declare(strict_types=1);

namespace App\Rules;

/**
 * Interprète un petit AST de règles (JSON, jamais une chaîne à parser ni un
 * `eval()`) contre un contexte de données. Générique — ne connaît rien du
 * métier qui l'utilise (MCCC ou autre) : celui-ci lui fournit son contexte.
 *
 * Nœuds supportés (MVP) :
 *   - comparison { op, left, right }        — op: == != > >= < <=
 *   - each       { collection, field, op, value }  (vrai si TOUS les éléments respectent op)
 *   - literal    { value }
 *   - aggregate  { fn: COUNT|SUM|MIN|MAX|AVG, collection, field? }
 *
 * Extensible plus tard (mêmes formes de retour, pas de réécriture) : logical
 * (ET/OU/NON), conditional (SI/ALORS), quantifier (généralisation ALL/ANY/NONE
 * de `each` à un prédicat arbitraire).
 */
final class RuleEvaluator
{
    /**
     * @param list<array<string, mixed>>               $rules
     * @param array<string, list<array<string, mixed>>> $context
     *
     * @return list<RuleResult>
     */
    public function evaluateAll(array $rules, array $context): array
    {
        return array_map(fn (array $rule): RuleResult => $this->evaluate($rule, $context), $rules);
    }

    /**
     * @param array<string, mixed>                      $rule
     * @param array<string, list<array<string, mixed>>> $context
     */
    public function evaluate(array $rule, array $context): RuleResult
    {
        $key = (string) ($rule['key'] ?? '');
        $label = (string) ($rule['label'] ?? $key);
        $severity = \in_array($rule['severity'] ?? null, ['info', 'warning', 'error'], true) ? $rule['severity'] : 'error';
        $node = \is_array($rule['node'] ?? null) ? $rule['node'] : [];

        $outcome = $this->evalNode($node, $context);

        return new RuleResult(
            ruleKey: $key,
            valid: $outcome['valid'],
            severity: $severity,
            message: $label,
            expected: $outcome['expected'],
            actual: $outcome['actual'],
            path: $outcome['path'],
        );
    }

    /**
     * @param array<string, mixed>                      $node
     * @param array<string, list<array<string, mixed>>> $context
     *
     * @return array{valid: bool, expected: mixed, actual: mixed, path: string}
     */
    private function evalNode(array $node, array $context): array
    {
        return match ($node['kind'] ?? null) {
            'comparison' => $this->evalComparison($node, $context),
            'each' => $this->evalEach($node, $context),
            default => ['valid' => true, 'expected' => null, 'actual' => null, 'path' => ''],
        };
    }

    /** @return array{valid: bool, expected: mixed, actual: mixed, path: string} */
    private function evalComparison(array $node, array $context): array
    {
        $leftNode = \is_array($node['left'] ?? null) ? $node['left'] : [];
        $right = $this->evalExpr(\is_array($node['right'] ?? null) ? $node['right'] : [], $context);
        $left = $this->evalExpr($leftNode, $context);

        return [
            'valid' => $this->compare((string) ($node['op'] ?? '=='), $left, $right),
            'expected' => $right,
            'actual' => $left,
            'path' => (string) ($leftNode['collection'] ?? ''),
        ];
    }

    /** @return array{valid: bool, expected: mixed, actual: mixed, path: string} */
    private function evalEach(array $node, array $context): array
    {
        $collection = (string) ($node['collection'] ?? '');
        $field = (string) ($node['field'] ?? '');
        $op = (string) ($node['op'] ?? '==');
        $expected = $this->evalExpr(\is_array($node['value'] ?? null) ? $node['value'] : [], $context);

        $failing = [];
        foreach ($this->rows($collection, $context) as $row) {
            $value = $row[$field] ?? null;
            if (!$this->compare($op, $value, $expected)) {
                $failing[] = $value;
            }
        }

        return [
            'valid' => $failing === [],
            'expected' => $expected,
            'actual' => $failing === [] ? null : $failing,
            'path' => $collection.'.'.$field,
        ];
    }

    /**
     * @param array<string, mixed>                      $node
     * @param array<string, list<array<string, mixed>>> $context
     */
    private function evalExpr(array $node, array $context): mixed
    {
        return match ($node['kind'] ?? null) {
            'literal' => $node['value'] ?? null,
            'aggregate' => $this->evalAggregate($node, $context),
            default => null,
        };
    }

    /** @param array<string, list<array<string, mixed>>> $context */
    private function evalAggregate(array $node, array $context): float
    {
        $rows = $this->rows((string) ($node['collection'] ?? ''), $context);
        $field = $node['field'] ?? null;
        $values = null !== $field
            ? array_map(static fn (array $r): float => (float) ($r[$field] ?? 0), $rows)
            : [];

        return match (strtoupper((string) ($node['fn'] ?? ''))) {
            'COUNT' => (float) \count($rows),
            'SUM' => array_sum($values),
            'MIN' => [] === $values ? 0.0 : min($values),
            'MAX' => [] === $values ? 0.0 : max($values),
            'AVG' => [] === $values ? 0.0 : array_sum($values) / \count($values),
            default => 0.0,
        };
    }

    /**
     * @param array<string, list<array<string, mixed>>> $context
     *
     * @return list<array<string, mixed>>
     */
    private function rows(string $collection, array $context): array
    {
        $rows = $context[$collection] ?? [];

        return \is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    private function compare(string $op, mixed $left, mixed $right): bool
    {
        if (null === $left || null === $right) {
            return false;
        }
        $l = (float) $left;
        $r = (float) $right;

        return match ($op) {
            '==' => abs($l - $r) < 0.001,
            '!=' => abs($l - $r) >= 0.001,
            '>' => $l > $r,
            '>=' => $l >= $r,
            '<' => $l < $r,
            '<=' => $l <= $r,
            default => false,
        };
    }
}
