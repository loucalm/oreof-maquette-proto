<?php

declare(strict_types=1);

namespace App\Tests\Diplome\Rule;

use App\Diplome\Check\StructureContextBuilder;
use App\Diplome\Rule\CompiledRule;
use App\Diplome\Rule\RuleTemplateRegistry;
use App\Entity\Formation;
use App\Maquette\Doc\MaquetteDoc;
use App\Maquette\Doc\TreeNode;
use App\Rules\RuleEvaluator;
use App\Rules\RuleResult;
use App\Tests\Support\DatabaseTestCase;

final class RuleTemplatesTest extends DatabaseTestCase
{
    private RuleTemplateRegistry $registry;
    private StructureContextBuilder $builder;
    private RuleEvaluator $evaluator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = static::getContainer()->get(RuleTemplateRegistry::class);
        $this->builder = new StructureContextBuilder();
        $this->evaluator = new RuleEvaluator();
    }

    public function testChildCountCompilesToNonBlockingMinAndBlockingMax(): void
    {
        $rules = $this->registry->get('child_count')->compile(['childType' => 'ue', 'min' => 3, 'max' => 6]);
        self::assertCount(2, $rules);
        self::assertSame(['min', 'max'], array_map(static fn (CompiledRule $r) => $r->part, $rules));
        self::assertSame([false, true], array_map(static fn (CompiledRule $r) => $r->blocking, $rules));

        $semestre = $this->node('s1', 'semestre', children: array_map(fn (int $i) => $this->node("u$i", 'ue'), range(1, 7)));
        $results = $this->check($rules, $semestre);

        self::assertTrue($results[0]->valid);
        self::assertFalse($results[1]->valid);
    }

    public function testChoiceBlocCountsAsOneChild(): void
    {
        $choix = $this->node('b', 'bloc_choix', ['ects' => 4], array_map(fn (int $i) => $this->node("c$i", 'ue', ['ects' => 4]), range(1, 4)));
        $semestre = $this->node('s1', 'semestre', children: [$this->node('u1', 'ue'), $this->node('u2', 'ue'), $choix]);

        $rules = $this->registry->get('child_count')->compile(['childType' => 'ue', 'min' => 3, 'max' => 3]);
        self::assertTrue($this->check($rules, $semestre)[0]->valid);
        self::assertTrue($this->check($rules, $semestre)[1]->valid);
        self::assertSame(3.0, $this->check($rules, $semestre)[0]->actual);
    }

    public function testRatioOfSaeEcts(): void
    {
        $rules = $this->registry->get('ratio')->compile(['attribute' => 'ects', 'numeratorNature' => 'sae', 'op' => '>=', 'percent' => 40]);
        self::assertCount(1, $rules);

        $make = fn (int $sae) => $this->node('s1', 'semestre', children: [
            $this->node('e1', 'ec', ['ects' => $sae, 'natureElement' => 'sae']),
            $this->node('e2', 'ec', ['ects' => 30 - $sae, 'natureElement' => 'ec']),
        ]);

        self::assertFalse($this->check($rules, $make(10))[0]->valid);
        self::assertTrue($this->check($rules, $make(12))[0]->valid);
    }

    public function testRatioBlockingAndPartFollowTheOperator(): void
    {
        $compile = fn (string $op) => $this->registry->get('ratio')->compile(['attribute' => 'ects', 'numeratorNature' => 'sae', 'op' => $op, 'percent' => 40])[0];

        foreach (['>=', '>'] as $op) {
            self::assertFalse($compile($op)->blocking);
            self::assertSame('min', $compile($op)->part);
        }
        foreach (['<=', '<'] as $op) {
            self::assertTrue($compile($op)->blocking);
            self::assertSame('max', $compile($op)->part);
        }
        self::assertTrue($compile('==')->blocking);
        self::assertSame('single', $compile('==')->part);
    }

    public function testRatioIsValidWhenScopeHasNoLeaves(): void
    {
        $empty = $this->node('s1', 'semestre');
        foreach (['>=', '<=', '=='] as $op) {
            $rules = $this->registry->get('ratio')->compile(['attribute' => 'ects', 'numeratorNature' => 'sae', 'op' => $op, 'percent' => 40]);
            self::assertTrue($this->check($rules, $empty)[0]->valid, $op);
        }
    }

    public function testRatioBoundaryTolerance(): void
    {
        // 1/3 = 0,3333... : >= 33,33 % valide, >= 33,34 % invalide (ordre : pas de tolérance à 0,001).
        $third = $this->node('s1', 'semestre', children: [
            $this->node('e1', 'ec', ['ects' => 1, 'natureElement' => 'sae']),
            $this->node('e2', 'ec', ['ects' => 2, 'natureElement' => 'ec']),
        ]);
        $ratio = fn (string $op, float $percent) => $this->registry->get('ratio')->compile(['attribute' => 'ects', 'numeratorNature' => 'sae', 'op' => $op, 'percent' => $percent]);

        self::assertTrue($this->check($ratio('>=', 33.33), $third)[0]->valid);
        self::assertFalse($this->check($ratio('>=', 33.34), $third)[0]->valid);
        self::assertFalse($this->check($ratio('<=', 33.33), $third)[0]->valid);
        self::assertTrue($this->check($ratio('<=', 33.34), $third)[0]->valid);

        // Exactement 12/30 = 40 % : >= et <= valides, == valide (tolérance 0,001 sur la fraction).
        $exact = $this->node('s2', 'semestre', children: [
            $this->node('e1', 'ec', ['ects' => 12, 'natureElement' => 'sae']),
            $this->node('e2', 'ec', ['ects' => 18, 'natureElement' => 'ec']),
        ]);
        foreach (['>=', '<=', '=='] as $op) {
            self::assertTrue($this->check($ratio($op, 40), $exact)[0]->valid, $op);
        }
        self::assertFalse($this->check($ratio('>', 40), $exact)[0]->valid);
    }

    public function testSameChildValueOnChoiceBloc(): void
    {
        $rules = $this->registry->get('same_child_value')->compile(['attribute' => 'ects']);
        $bloc = fn (array $ects) => $this->node('b', 'bloc_choix', children: array_map(fn (int $i, int $e) => $this->node("c$i", 'ue', ['ects' => $e]), array_keys($ects), $ects));

        self::assertFalse($this->check($rules, $bloc([3, 3, 2]))[0]->valid);
        self::assertTrue($this->check($rules, $bloc([3, 3, 3]))[0]->valid);
    }

    public function testLeavesTakeOnlyFirstChildOfChoiceBloc(): void
    {
        $choix = $this->node('b', 'bloc_choix', children: [$this->node('c1', 'ec', ['ects' => 2]), $this->node('c2', 'ec', ['ects' => 9])]);
        $ue = $this->node('u1', 'ue', children: [$this->node('e1', 'ec', ['ects' => 3]), $choix]);
        $context = $this->builder->contextFor($this->doc([$ue]), $ue);

        self::assertSame(['e1', 'c1'], array_column($context['leaves'], 'nid'));
    }

    public function testRequiredElementAndAttributeSumAndValue(): void
    {
        $semestre = $this->node('s1', 'semestre', children: [
            $this->node('u1', 'ue', ['ects' => 12]),
            $this->node('st', 'stage', ['ects' => 18, 'natureElement' => 'stage']),
        ]);

        $required = $this->registry->get('required_element')->compile(['typeKey' => 'stage', 'nature' => 'stage']);
        self::assertTrue($this->check($required, $semestre)[0]->valid);
        $missing = $this->registry->get('required_element')->compile(['typeKey' => 'stage', 'nature' => 'sae']);
        self::assertFalse($this->check($missing, $semestre)[0]->valid);

        $sum = $this->registry->get('attribute_sum')->compile(['attribute' => 'ects', 'min' => 30, 'max' => 30]);
        self::assertTrue($this->check($sum, $semestre)[0]->valid);
        self::assertTrue($this->check($sum, $semestre)[1]->valid);

        $value = $this->registry->get('attribute_value')->compile(['attribute' => 'ects', 'max' => 15]);
        self::assertCount(1, $value);
        self::assertFalse($this->check($value, $semestre)[0]->valid);
    }

    public function testRequiredElementWithImposedUidMatchesOnlyThatElement(): void
    {
        $semestre = $this->node('s1', 'semestre', children: [
            $this->node('u1', 'ue'),
            $this->node('u2', 'ue', ['_imposed' => 'y']),
        ]);
        $template = $this->registry->get('required_element');

        self::assertFalse($this->check($template->compile(['typeKey' => 'ue', 'imposed' => 'x']), $semestre)[0]->valid);
        self::assertTrue($this->check($template->compile(['typeKey' => 'ue', 'imposed' => 'y']), $semestre)[0]->valid);
        // sans `imposed`, n'importe quel élément du type convient
        self::assertTrue($this->check($template->compile(['typeKey' => 'ue']), $semestre)[0]->valid);
    }

    public function testRootScopes(): void
    {
        $mono = $this->doc([$this->node('a', 'annee')]);
        self::assertSame([null], $this->builder->rootScopes($mono));

        $p1 = $this->node('p1', 'parcours');
        $multi = $this->doc([$p1, $this->node('p2', 'parcours')]);
        self::assertCount(2, $this->builder->rootScopes($multi));
        self::assertSame($p1, $this->builder->rootScopes($multi)[0]);
    }

    public function testEveryTemplateIsDiscoveredByTheRegistry(): void
    {
        self::assertEqualsCanonicalizing(
            ['child_count', 'attribute_sum', 'attribute_value', 'ratio', 'same_child_value', 'required_element'],
            array_keys($this->registry->all()),
        );
    }

    /**
     * @param list<CompiledRule> $rules
     *
     * @return list<RuleResult>
     */
    private function check(array $rules, TreeNode $scope): array
    {
        $context = $this->builder->contextFor($this->doc([$scope]), $scope);

        return array_map(
            fn (CompiledRule $r) => $this->evaluator->evaluate(['key' => 'k', 'label' => $r->message, 'node' => $r->node], $context),
            $rules,
        );
    }

    /**
     * @param array<string, mixed> $attributes
     * @param list<TreeNode>       $children
     */
    private function node(string $nid, string $type, array $attributes = [], array $children = []): TreeNode
    {
        $node = new TreeNode($nid, $type, attributes: $attributes);
        $node->children = $children;

        return $node;
    }

    /** @param list<TreeNode> $roots */
    private function doc(array $roots): MaquetteDoc
    {
        $doc = new MaquetteDoc(new Formation('Test'));
        $doc->roots = $roots;
        $doc->reindex();

        return $doc;
    }
}
