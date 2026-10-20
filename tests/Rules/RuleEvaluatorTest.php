<?php

declare(strict_types=1);

namespace App\Tests\Rules;

use App\Rules\RuleEvaluator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RuleEvaluatorTest extends TestCase
{
    private RuleEvaluator $evaluator;

    protected function setUp(): void
    {
        $this->evaluator = new RuleEvaluator();
    }

    /**
     * @return list<array{string, mixed, mixed, bool}>
     */
    public static function comparisonOperatorData(): array
    {
        return [
            ['==', 5.0, 5.0, true],
            ['==', 5.0, 5.0005, true],   // tolérance < 0.001
            ['==', 5.0, 5.001, false],    // tolérance >= 0.001
            ['!=', 5.0, 5.0, false],
            ['!=', 5.0, 5.0005, false],   // tolérance < 0.001
            ['!=', 5.0, 5.001, true],     // tolérance >= 0.001
            ['>', 5.0, 3.0, true],
            ['>', 5.0, 5.0, false],
            ['>', 3.0, 5.0, false],
            ['>=', 5.0, 5.0, true],
            ['>=', 5.0, 3.0, true],
            ['>=', 3.0, 5.0, false],
            ['<', 3.0, 5.0, true],
            ['<', 5.0, 5.0, false],
            ['<', 5.0, 3.0, false],
            ['<=', 5.0, 5.0, true],
            ['<=', 3.0, 5.0, true],
            ['<=', 5.0, 3.0, false],
        ];
    }

    #[DataProvider('comparisonOperatorData')]
    public function testComparisonOperators(string $op, mixed $leftValue, mixed $rightValue, bool $expectedValid): void
    {
        $rule = [
            'key' => 'test_comparison',
            'label' => 'Test comparison',
            'severity' => 'error',
            'node' => [
                'kind' => 'comparison',
                'op' => $op,
                'left' => [
                    'kind' => 'aggregate',
                    'fn' => 'SUM',
                    'collection' => 'items',
                    'field' => 'value',
                ],
                'right' => [
                    'kind' => 'literal',
                    'value' => $rightValue,
                ],
            ],
        ];

        $context = [
            'items' => [
                ['value' => $leftValue],
            ],
        ];

        $result = $this->evaluator->evaluate($rule, $context);

        $this->assertSame($expectedValid, $result->valid);
        $this->assertSame('test_comparison', $result->ruleKey);
        $this->assertSame('error', $result->severity);
    }

    public function testComparisonWithNullValueReturnsFalse(): void
    {
        $rule = [
            'key' => 'test_null',
            'label' => 'Test null',
            'node' => [
                'kind' => 'comparison',
                'op' => '==',
                'left' => [
                    'kind' => 'literal',
                    'value' => null,
                ],
                'right' => [
                    'kind' => 'literal',
                    'value' => 5.0,
                ],
            ],
        ];

        $result = $this->evaluator->evaluate($rule, []);

        $this->assertFalse($result->valid);
    }

    public function testComparisonWithNullOnRightReturnsFalse(): void
    {
        $rule = [
            'key' => 'test_null_right',
            'label' => 'Test null right',
            'node' => [
                'kind' => 'comparison',
                'op' => '==',
                'left' => [
                    'kind' => 'literal',
                    'value' => 5.0,
                ],
                'right' => [
                    'kind' => 'literal',
                    'value' => null,
                ],
            ],
        ];

        $result = $this->evaluator->evaluate($rule, []);

        $this->assertFalse($result->valid);
    }

    /**
     * @return list<array{string, array<int, array<string, mixed>>, float}>
     */
    public static function aggregateData(): array
    {
        return [
            ['COUNT', [['v' => 1], ['v' => 2], ['v' => 3]], 3.0],
            ['SUM', [['v' => 1], ['v' => 2], ['v' => 3]], 6.0],
            ['MIN', [['v' => 1], ['v' => 2], ['v' => 3]], 1.0],
            ['MAX', [['v' => 1], ['v' => 2], ['v' => 3]], 3.0],
            ['AVG', [['v' => 1], ['v' => 2], ['v' => 3]], 2.0],
        ];
    }

    #[DataProvider('aggregateData')]
    public function testAggregateOnNonEmptyCollection(string $fn, array $items, float $expected): void
    {
        $rule = [
            'key' => 'test_aggregate',
            'label' => 'Test aggregate',
            'node' => [
                'kind' => 'comparison',
                'op' => '==',
                'left' => [
                    'kind' => 'aggregate',
                    'fn' => $fn,
                    'collection' => 'items',
                    'field' => 'v',
                ],
                'right' => [
                    'kind' => 'literal',
                    'value' => $expected,
                ],
            ],
        ];

        $context = ['items' => $items];

        $result = $this->evaluator->evaluate($rule, $context);

        $this->assertTrue($result->valid);
    }

    /**
     * @return list<array{string, float}>
     */
    public static function aggregateEmptyCollectionData(): array
    {
        return [
            ['MIN', 0.0],
            ['MAX', 0.0],
            ['AVG', 0.0],
        ];
    }

    #[DataProvider('aggregateEmptyCollectionData')]
    public function testAggregateOnEmptyCollectionReturnsZero(string $fn, float $expected): void
    {
        $rule = [
            'key' => 'test_aggregate_empty',
            'label' => 'Test aggregate empty',
            'node' => [
                'kind' => 'comparison',
                'op' => '==',
                'left' => [
                    'kind' => 'aggregate',
                    'fn' => $fn,
                    'collection' => 'items',
                    'field' => 'v',
                ],
                'right' => [
                    'kind' => 'literal',
                    'value' => $expected,
                ],
            ],
        ];

        $context = ['items' => []];

        $result = $this->evaluator->evaluate($rule, $context);

        $this->assertTrue($result->valid);
    }

    public function testCountOnEmptyCollectionReturnsZero(): void
    {
        $rule = [
            'key' => 'test_count_empty',
            'label' => 'Test COUNT empty',
            'node' => [
                'kind' => 'comparison',
                'op' => '==',
                'left' => [
                    'kind' => 'aggregate',
                    'fn' => 'COUNT',
                    'collection' => 'items',
                ],
                'right' => [
                    'kind' => 'literal',
                    'value' => 0.0,
                ],
            ],
        ];

        $context = ['items' => []];

        $result = $this->evaluator->evaluate($rule, $context);

        $this->assertTrue($result->valid);
    }

    public function testSumOnEmptyCollectionReturnsZero(): void
    {
        $rule = [
            'key' => 'test_sum_empty',
            'label' => 'Test SUM empty',
            'node' => [
                'kind' => 'comparison',
                'op' => '==',
                'left' => [
                    'kind' => 'aggregate',
                    'fn' => 'SUM',
                    'collection' => 'items',
                    'field' => 'v',
                ],
                'right' => [
                    'kind' => 'literal',
                    'value' => 0.0,
                ],
            ],
        ];

        $context = ['items' => []];

        $result = $this->evaluator->evaluate($rule, $context);

        $this->assertTrue($result->valid);
    }

    public function testUnknownAggregateFunctionReturnsZero(): void
    {
        $rule = [
            'key' => 'test_unknown_fn',
            'label' => 'Test unknown fn',
            'node' => [
                'kind' => 'comparison',
                'op' => '==',
                'left' => [
                    'kind' => 'aggregate',
                    'fn' => 'UNKNOWN',
                    'collection' => 'items',
                    'field' => 'v',
                ],
                'right' => [
                    'kind' => 'literal',
                    'value' => 0.0,
                ],
            ],
        ];

        $context = ['items' => [['v' => 10]]];

        $result = $this->evaluator->evaluate($rule, $context);

        $this->assertTrue($result->valid);
    }

    public function testMissingCollectionTreatedAsEmpty(): void
    {
        $rule = [
            'key' => 'test_missing_collection',
            'label' => 'Test missing collection',
            'node' => [
                'kind' => 'comparison',
                'op' => '==',
                'left' => [
                    'kind' => 'aggregate',
                    'fn' => 'COUNT',
                    'collection' => 'nonexistent',
                ],
                'right' => [
                    'kind' => 'literal',
                    'value' => 0.0,
                ],
            ],
        ];

        $context = [];

        $result = $this->evaluator->evaluate($rule, $context);

        $this->assertTrue($result->valid);
    }

    public function testEachAllValidReturnsTrue(): void
    {
        $rule = [
            'key' => 'test_each_all_valid',
            'label' => 'Test each all valid',
            'node' => [
                'kind' => 'each',
                'collection' => 'items',
                'field' => 'value',
                'op' => '>',
                'value' => [
                    'kind' => 'literal',
                    'value' => 0,
                ],
            ],
        ];

        $context = [
            'items' => [
                ['value' => 1],
                ['value' => 2],
                ['value' => 3],
            ],
        ];

        $result = $this->evaluator->evaluate($rule, $context);

        $this->assertTrue($result->valid);
        $this->assertNull($result->actual);
        $this->assertSame('items.value', $result->path);
    }

    public function testEachOneInvalidReturnsFalseWithFailingValues(): void
    {
        $rule = [
            'key' => 'test_each_one_invalid',
            'label' => 'Test each one invalid',
            'node' => [
                'kind' => 'each',
                'collection' => 'items',
                'field' => 'value',
                'op' => '>',
                'value' => [
                    'kind' => 'literal',
                    'value' => 0,
                ],
            ],
        ];

        $context = [
            'items' => [
                ['value' => 1],
                ['value' => -1],
                ['value' => 3],
            ],
        ];

        $result = $this->evaluator->evaluate($rule, $context);

        $this->assertFalse($result->valid);
        $this->assertSame([-1], $result->actual);
        $this->assertSame('items.value', $result->path);
    }

    public function testEachMultipleInvalidReturnsAllFailingValues(): void
    {
        $rule = [
            'key' => 'test_each_multiple_invalid',
            'label' => 'Test each multiple invalid',
            'node' => [
                'kind' => 'each',
                'collection' => 'items',
                'field' => 'value',
                'op' => '>',
                'value' => [
                    'kind' => 'literal',
                    'value' => 0,
                ],
            ],
        ];

        $context = [
            'items' => [
                ['value' => -1],
                ['value' => -2],
                ['value' => 3],
            ],
        ];

        $result = $this->evaluator->evaluate($rule, $context);

        $this->assertFalse($result->valid);
        $this->assertSame([-1, -2], $result->actual);
        $this->assertSame('items.value', $result->path);
    }

    public function testUnknownNodeKindReturnsValid(): void
    {
        $rule = [
            'key' => 'test_unknown_kind',
            'label' => 'Test unknown kind',
            'node' => [
                'kind' => 'unknown_kind',
            ],
        ];

        $result = $this->evaluator->evaluate($rule, []);

        $this->assertTrue($result->valid);
    }

    public function testEmptyNodeReturnsValid(): void
    {
        $rule = [
            'key' => 'test_empty_node',
            'label' => 'Test empty node',
            'node' => [],
        ];

        $result = $this->evaluator->evaluate($rule, []);

        $this->assertTrue($result->valid);
    }

    public function testInvalidSeverityDefaultsToError(): void
    {
        $rule = [
            'key' => 'test_severity',
            'label' => 'Test severity',
            'severity' => 'invalid',
            'node' => [],
        ];

        $result = $this->evaluator->evaluate($rule, []);

        $this->assertSame('error', $result->severity);
    }

    public function testWarningSeverityIsPreserved(): void
    {
        $rule = [
            'key' => 'test_warning',
            'label' => 'Test warning',
            'severity' => 'warning',
            'node' => [],
        ];

        $result = $this->evaluator->evaluate($rule, []);

        $this->assertSame('warning', $result->severity);
    }

    public function testMessageDefaultsToLabel(): void
    {
        $rule = [
            'key' => 'test_message_label',
            'label' => 'Custom label',
            'node' => [],
        ];

        $result = $this->evaluator->evaluate($rule, []);

        $this->assertSame('Custom label', $result->message);
    }

    public function testMessageDefaultsToKeyWhenNoLabel(): void
    {
        $rule = [
            'key' => 'my_rule_key',
            'node' => [],
        ];

        $result = $this->evaluator->evaluate($rule, []);

        $this->assertSame('my_rule_key', $result->message);
    }

    public function testEvaluateAllReturnsResultsInOrder(): void
    {
        $rules = [
            [
                'key' => 'rule1',
                'label' => 'Rule 1',
                'node' => [
                    'kind' => 'comparison',
                    'op' => '==',
                    'left' => ['kind' => 'literal', 'value' => 1],
                    'right' => ['kind' => 'literal', 'value' => 1],
                ],
            ],
            [
                'key' => 'rule2',
                'label' => 'Rule 2',
                'node' => [
                    'kind' => 'comparison',
                    'op' => '==',
                    'left' => ['kind' => 'literal', 'value' => 1],
                    'right' => ['kind' => 'literal', 'value' => 2],
                ],
            ],
            [
                'key' => 'rule3',
                'label' => 'Rule 3',
                'node' => [
                    'kind' => 'comparison',
                    'op' => '==',
                    'left' => ['kind' => 'literal', 'value' => 3],
                    'right' => ['kind' => 'literal', 'value' => 3],
                ],
            ],
        ];

        $results = $this->evaluator->evaluateAll($rules, []);

        $this->assertCount(3, $results);
        $this->assertSame('rule1', $results[0]->ruleKey);
        $this->assertTrue($results[0]->valid);
        $this->assertSame('rule2', $results[1]->ruleKey);
        $this->assertFalse($results[1]->valid);
        $this->assertSame('rule3', $results[2]->ruleKey);
        $this->assertTrue($results[2]->valid);
    }

    public function testAggregateWhereFiltersRows(): void
    {
        $context = ['rows' => [
            ['nature' => 'sae', 'ects' => 5],
            ['nature' => 'ec', 'ects' => 10],
            ['nature' => 'sae', 'ects' => 7],
        ]];
        $sum = ['kind' => 'aggregate', 'fn' => 'SUM', 'collection' => 'rows', 'field' => 'ects', 'where' => ['field' => 'nature', 'op' => '==', 'value' => 'sae']];
        $result = $this->evaluator->evaluate(['key' => 'k', 'node' => [
            'kind' => 'comparison', 'op' => '==', 'left' => $sum, 'right' => ['kind' => 'literal', 'value' => 12],
        ]], $context);

        $this->assertTrue($result->valid);
        $this->assertSame(12.0, $result->actual);
    }

    public function testRatioExpressionDividesAndToleratesZero(): void
    {
        $context = ['rows' => [['nature' => 'sae', 'ects' => 12], ['nature' => 'ec', 'ects' => 18]], 'empty' => []];
        $ratio = static fn (string $denominator): array => ['kind' => 'ratio',
            'numerator' => ['kind' => 'aggregate', 'fn' => 'SUM', 'collection' => 'rows', 'field' => 'ects', 'where' => ['field' => 'nature', 'op' => '==', 'value' => 'sae']],
            'denominator' => ['kind' => 'aggregate', 'fn' => 'SUM', 'collection' => $denominator, 'field' => 'ects'],
        ];
        $eval = fn (array $left) => $this->evaluator->evaluate(['key' => 'k', 'node' => [
            'kind' => 'comparison', 'op' => '==', 'left' => $left, 'right' => ['kind' => 'literal', 'value' => 0],
        ]], $context);

        $this->assertEqualsWithDelta(0.4, $eval($ratio('rows'))->actual, 0.0001);
        $this->assertSame(0.0, $eval($ratio('empty'))->actual);
    }
}
