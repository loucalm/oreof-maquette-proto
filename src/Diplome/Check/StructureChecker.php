<?php

declare(strict_types=1);

namespace App\Diplome\Check;

use App\Diplome\ImposedHostLocator;
use App\Diplome\Rule\CompiledRule;
use App\Diplome\Rule\RuleTemplateRegistry;
use App\Diplome\Spec\Bound;
use App\Diplome\Spec\RuleSpec;
use App\Diplome\Spec\VersionConfig;
use App\Maquette\Doc\MaquetteDoc;
use App\Maquette\Doc\TreeNode;
use App\Rules\RuleEvaluator;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Vérifie une maquette contre la configuration de sa version : bornes des
 * niveaux (nombre, ECTS, somme d'ECTS), durée, éléments imposés et règles
 * paramétrées. Tout passe par les gabarits de règles et RuleEvaluator.
 */
final class StructureChecker
{
    public function __construct(
        private readonly RuleTemplateRegistry $templates,
        private readonly RuleEvaluator $evaluator,
        private readonly StructureContextBuilder $contexts,
        private readonly ImposedHostLocator $locator,
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
        $run = function (string $prefix, string $template, array $params, array $scopes, bool $forceWarning = false, ?callable $paramsFor = null, bool $cursus = false) use ($doc, &$cache, &$violations): void {
            foreach ($scopes as $scope) {
                $scopeParams = null !== $paramsFor ? $paramsFor($scope, $params) : $params;
                foreach ($this->templates->get($template)->compile($scopeParams) as $compiled) {
                    $ctx = $cursus
                        ? ($cache['cursus:'.($scope?->nid ?? '')] ??= $this->contexts->cursusContextFor($doc, $scope))
                        : ($cache[$scope?->nid ?? ''] ??= $this->contexts->contextFor($doc, $scope));
                    $violation = $this->evaluate($prefix, $compiled, $scope, $ctx, $forceWarning);
                    if (null !== $violation) {
                        $violations[] = $violation;
                    }
                }
            }
        };

        $firstLevel = $levels[0]->typeKey ?? null;
        if (null !== $firstLevel && null !== $structure->duration) {
            $run('duration', 'child_count', ['childType' => $firstLevel] + self::bounds($structure->duration), $this->contexts->cursusScopes($doc), false, null, true);
        }

        // surcharges de borne : nid de l'hôte → typeKey enfant → borne
        $overrides = [];
        foreach ($structure->imposed as $imposed) {
            if (null === $imposed->countOverride) {
                continue;
            }
            foreach ($this->locator->hostsIn($doc, $imposed) as $host) {
                $overrides[$host->nid][$imposed->typeKey] = $imposed->countOverride;
            }
        }

        foreach ($levels as $i => $level) {
            $parents = 0 === $i ? $rootScopes : $this->nodesOfType($doc, $levels[$i - 1]->typeKey);
            $key = 'level:'.$level->typeKey;
            if (null !== $level->count) {
                $run($key.':count', 'child_count', ['childType' => $level->typeKey], $parents, false,
                    static fn (?TreeNode $scope, array $p): array => $p + self::bounds($overrides[$scope?->nid ?? ''][$level->typeKey] ?? $level->count));
            }
            if (null !== $level->ects) {
                $run($key.':ects', 'attribute_value', ['attribute' => 'ects'] + self::bounds($level->ects), $parents);
            }
            if (null !== $level->ectsSum) {
                $run($key.':ectsSum', 'attribute_sum', ['attribute' => 'ects'] + self::bounds($level->ectsSum), $this->nodesOfType($doc, $level->typeKey));
            }
        }

        foreach ($structure->imposed as $imposed) {
            $params = ['typeKey' => $imposed->typeKey, 'nature' => $imposed->nature ?? '', 'imposed' => $imposed->uid];
            foreach ($this->locator->hostsIn($doc, $imposed) as $host) {
                foreach ($this->templates->get('required_element')->compile($params) as $compiled) {
                    $ctx = $cache[$host->nid] ??= $this->contexts->contextFor($doc, $host);
                    $label = sprintf('Élément imposé manquant : %s', '' !== $imposed->label ? $imposed->label : $imposed->typeKey);
                    $violation = $this->evaluate('imposed:'.$imposed->uid, new CompiledRule($compiled->node, true, $label), $host, $ctx, false);
                    if (null !== $violation) {
                        $violations[] = $violation;
                    }
                }
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
            $run('rule:'.$rule->uid, $rule->template, $rule->params, $this->nodesOfType($doc, $rule->scopeLevel), RuleSpec::WARNING === $rule->severity);
        }

        return $violations;
    }

    /**
     * @param array<string, list<array<string, mixed>>> $context
     */
    private function evaluate(string $prefix, CompiledRule $compiled, ?TreeNode $scope, array $context, bool $forceWarning): ?StructureViolation
    {
        $key = 'single' === $compiled->part ? $prefix : $prefix.':'.$compiled->part;
        $result = $this->evaluator->evaluate(['key' => $key, 'label' => $compiled->message, 'severity' => 'error', 'node' => $compiled->node], $context);
        if ($result->valid) {
            return null;
        }

        return new StructureViolation(
            $key,
            $scope?->nid ?? '',
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
