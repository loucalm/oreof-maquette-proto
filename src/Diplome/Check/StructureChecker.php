<?php

declare(strict_types=1);

namespace App\Diplome\Check;

use App\Diplome\Rule\CompiledRule;
use App\Diplome\Rule\RuleTemplateRegistry;
use App\Diplome\Spec\Bound;
use App\Diplome\Spec\RuleSpec;
use App\Diplome\Spec\StructureSpec;
use App\Diplome\Spec\VersionConfig;
use App\Maquette\Doc\MaquetteDoc;
use App\Maquette\Doc\TreeNode;
use App\Rules\RuleEvaluator;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Vérifie une maquette contre la configuration de sa version : bornes des
 * niveaux (nombre, ECTS, somme d'ECTS), durée et règles paramétrées. Tout passe par les gabarits de règles et RuleEvaluator.
 */
final class StructureChecker
{
    public function __construct(
        private readonly RuleTemplateRegistry $templates,
        private readonly RuleEvaluator $evaluator,
        private readonly StructureContextBuilder $contexts,
        private readonly ScopeSelector $selector,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /** @return list<StructureViolation> */
    public function check(MaquetteDoc $doc, VersionConfig $config): array
    {
        $structure = $config->structure;
        $levels = $structure->levels;
        $rootScopes = $this->contexts->rootScopes($doc);
        $violations = [];

        /** @var array<string, array<string, list<array<string, mixed>>>> $cache */
        $cache = [];
        // $reportAs : nid du nœud évalué → nid sous lequel l'écart est signalé (par défaut, le nœud lui-même)
        $run = function (string $prefix, string $template, array $params, array $scopes, bool $forceWarning = false, bool $cursus = false, array $reportAs = []) use ($doc, $config, &$cache, &$violations): void {
            foreach ($scopes as $scope) {
                foreach ($this->templates->get($template)->compile($params) as $compiled) {
                    $ctx = $cursus
                        ? ($cache['presence:'.($scope?->nid ?? '')] ??= $this->contexts->cursusPresenceContextFor($doc, $scope, $config))
                        : ($cache[$scope?->nid ?? ''] ??= $this->contexts->contextFor($doc, $scope, $config));
                    $violation = $this->evaluate($prefix, $compiled, $reportAs[$scope?->nid ?? ''] ?? $scope?->nid ?? '', $ctx, $forceWarning);
                    if (null !== $violation) {
                        $violations[] = $violation;
                    }
                }
            }
        };

        // la durée compte les nœuds du niveau choisi dans tout le cursus (descendants compris)
        if (null !== $structure->duration) {
            $run('duration', 'child_count', ['childType' => $structure->duration->levelKey] + self::bounds($structure->duration->bound), $this->contexts->cursusScopes($doc), false, true);
        }

        foreach ($levels as $i => $level) {
            $parents = 0 === $i ? $rootScopes : $this->nodesOfType($doc, $levels[$i - 1]->typeKey);
            $key = 'level:'.$level->typeKey;
            if (null !== $level->count) {
                $run($key.':count', 'child_count', ['childType' => $level->typeKey] + self::bounds($level->count), $parents);
            }
            if (null !== $level->ects) {
                $run($key.':ects', 'attribute_value', ['attribute' => 'ects'] + self::bounds($level->ects), $parents);
            }
            if (null !== $level->ectsSum) {
                $run($key.':ectsSum', 'attribute_sum', ['attribute' => 'ects'] + self::bounds($level->ectsSum), $this->nodesOfType($doc, $level->typeKey));
            }
        }

        foreach ($structure->rules as $rule) {
            if (!$this->templates->has($rule->template)) {
                // gabarit disparu : signalé (non bloquant) plutôt qu'ignoré en silence
                $violations[] = new StructureViolation(
                    'rule:'.$rule->uid.':unknown',
                    '',
                    $this->translator->trans('formation.check.unknown_rule', ['%template%' => $rule->template], 'formation'),
                    false,
                    null,
                    null,
                );
                continue;
            }
            [$scopes, $reportAs] = $this->ruleScopes($doc, $structure, $rule);
            $run('rule:'.$rule->uid, $rule->template, $rule->params, $scopes, RuleSpec::WARNING === $rule->severity, false, $reportAs);
        }

        return $violations;
    }

    /**
     * @param array<string, list<array<string, mixed>>> $context
     */
    private function evaluate(string $prefix, CompiledRule $compiled, string $scopeNid, array $context, bool $forceWarning): ?StructureViolation
    {
        $key = 'single' === $compiled->part ? $prefix : $prefix.':'.$compiled->part;
        $result = $this->evaluator->evaluate(['key' => $key, 'label' => $compiled->message, 'severity' => 'error', 'node' => $compiled->node], $context);
        if ($result->valid) {
            return null;
        }

        return new StructureViolation(
            $key,
            $scopeNid,
            $result->message,
            $compiled->blocking && !$forceWarning,
            self::number($result->actual),
            self::number($result->expected),
        );
    }

    private static function number(mixed $v): float|int|null
    {
        if (!\is_int($v) && !\is_float($v)) {
            return null;
        }

        return \is_float($v) && floor($v) === $v ? (int) $v : $v;
    }

    /**
     * Nœuds de portée d'une règle : tous ceux du niveau, ou, en portée positionnelle, ceux désignés cursus par cursus.
     * En `first` / `last`, l'écart est signalé sous le cursus (parcours feuille, '' en mono-parcours) et non sous le
     * nœud : quand la maquette grandit, le premier ou le dernier nœud change, l'écart reste le même. Un numéro garde
     * le nœud (un semestre numéroté garde son identité).
     *
     * @return array{0: list<TreeNode>, 1: array<string, string>} nœuds évalués, et nid évalué → nid signalé
     */
    private function ruleScopes(MaquetteDoc $doc, StructureSpec $structure, RuleSpec $rule): array
    {
        if (ScopeSelector::ALL === $rule->scopePosition) {
            return [$this->nodesOfType($doc, $rule->scopeLevel), []];
        }
        $startAt = 1;
        foreach ($structure->levels as $level) {
            if ($level->typeKey === $rule->scopeLevel) {
                $startAt = $level->startAt;
            }
        }
        $byCursus = \in_array($rule->scopePosition, [ScopeSelector::FIRST, ScopeSelector::LAST], true);
        $scopes = [];
        $reportAs = [];
        foreach ($this->contexts->cursusScopes($doc) as $cursus) {
            $atLevel = array_values(array_filter($this->contexts->cursusNodes($doc, $cursus), static fn (TreeNode $n): bool => $n->typeKey === $rule->scopeLevel));
            foreach ($this->selector->select($atLevel, $rule->scopePosition, $startAt) as $node) {
                if (isset($scopes[$node->nid])) {
                    continue; // un portail partagé par plusieurs cursus n'est contrôlé qu'une fois
                }
                $scopes[$node->nid] = $node;
                if ($byCursus) {
                    $reportAs[$node->nid] = $cursus?->nid ?? '';
                }
            }
        }

        return [array_values($scopes), $reportAs];
    }

    /** @return list<TreeNode> */
    private function nodesOfType(MaquetteDoc $doc, string $typeKey): array
    {
        return array_values(array_filter($doc->allNodes(), static fn (TreeNode $n): bool => $n->typeKey === $typeKey));
    }

    /** @return array{min?: int, max?: int} */
    private static function bounds(Bound $bound): array
    {
        return array_filter(['min' => $bound->min, 'max' => $bound->max], static fn (?int $v): bool => null !== $v);
    }
}
