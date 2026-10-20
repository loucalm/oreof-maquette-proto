<?php

declare(strict_types=1);

namespace App\Diplome\Wizard;

use App\Diplome\Rule\RuleTemplateRegistry;
use App\Diplome\Spec\ImposedElement;
use App\Diplome\Spec\LevelSpec;
use App\Diplome\Spec\RuleSpec;
use App\Diplome\Spec\StructureSpec;
use App\Diplome\StructurePreview;
use App\Diplome\WizardDraft;
use App\Form\Diplome\RuleSpecType;
use App\Form\Diplome\StructureType;

/** Étape « Structure » : niveaux, bornes, éléments imposés et règles du type de diplôme. */
final class StructureStep extends AbstractWizardStep
{
    public function __construct(
        private readonly RuleTemplateRegistry $templates,
        private readonly StructurePreview $preview,
    ) {
    }

    public function key(): string
    {
        return 'structure';
    }

    public function position(): int
    {
        return 40;
    }

    public function formType(): string
    {
        return StructureType::class;
    }

    public function initialData(WizardDraft $draft): array
    {
        $s = $draft->config()->structure;

        return [
            'duration' => $s->duration,
            'parcoursAllowed' => $s->parcoursAllowed,
            'natures' => $s->natures,
            'levels' => array_map(static fn (LevelSpec $l) => [
                'typeKey' => $l->typeKey, 'count' => $l->count, 'ects' => $l->ects, 'ectsSum' => $l->ectsSum, 'startAt' => $l->startAt,
            ], $s->levels),
            'imposed' => array_map(static fn (ImposedElement $i) => [
                'uid' => $i->uid, 'typeKey' => $i->typeKey, 'label' => $i->label, 'position' => $i->position,
                'nature' => $i->nature, 'locked' => $i->locked, 'countOverride' => $i->countOverride,
            ], $s->imposed),
            'rules' => array_map(fn (RuleSpec $r) => [
                'uid' => $r->uid, 'template' => $r->template, 'scopeLevel' => $r->scopeLevel, 'severity' => $r->severity,
                RuleSpecType::PARAMS_PREFIX.$r->template => $r->params,
            ], $s->rules),
        ];
    }

    public function apply(WizardDraft $draft, mixed $formData): void
    {
        $levels = array_values(array_map(
            static fn (array $l) => new LevelSpec((string) $l['typeKey'], $l['count'], $l['ects'], $l['ectsSum'], max(1, (int) ($l['startAt'] ?? 1))),
            $formData['levels'] ?? [],
        ));
        $chain = array_map(static fn (LevelSpec $l) => $l->typeKey, $levels);

        $imposed = [];
        foreach ($formData['imposed'] ?? [] as $i) {
            $at = array_search($i['typeKey'], $chain, true);
            $imposed[] = new ImposedElement(
                '' !== (string) ($i['uid'] ?? '') ? (string) $i['uid'] : 'imp-'.bin2hex(random_bytes(4)),
                (string) $i['typeKey'],
                trim((string) $i['label']),
                // le niveau parent d'un élément imposé est celui situé juste au-dessus de son type
                false === $at || 0 === $at ? '' : $chain[$at - 1],
                '' !== (string) ($i['position'] ?? '') ? (string) $i['position'] : 'all',
                '' !== (string) ($i['nature'] ?? '') ? (string) $i['nature'] : null,
                array_values(array_map('strval', $i['locked'] ?? [])),
                $i['countOverride'],
            );
        }

        $rules = [];
        foreach ($formData['rules'] ?? [] as $r) {
            $rules[] = new RuleSpec(
                '' !== (string) ($r['uid'] ?? '') ? (string) $r['uid'] : 'rule-'.bin2hex(random_bytes(4)),
                (string) $r['template'],
                $this->params((string) $r['template'], $r[RuleSpecType::PARAMS_PREFIX.$r['template']] ?? []),
                (string) $r['scopeLevel'],
                (string) $r['severity'],
            );
        }

        $structure = new StructureSpec($levels, (bool) $formData['parcoursAllowed'], $formData['duration'], $formData['natures'] ?? [], $imposed, $rules);
        $config = $draft->configData;
        $config['structure'] = $structure->toArray();
        $draft->configData = $config;
    }

    public function template(): string
    {
        return 'structure';
    }

    public function viewData(WizardDraft $draft): array
    {
        $roots = [];
        $previewError = null;
        try {
            $roots = $this->preview->roots($draft->config()->structure);
        } catch (\InvalidArgumentException $e) {
            $previewError = $e->getMessage();
        }

        return ['preview' => $roots, 'previewError' => $previewError];
    }

    /**
     * Paramètres saisis, restreints à ceux que le gabarit déclare (hors internes), typés.
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function params(string $template, array $input): array
    {
        $params = [];
        foreach ($this->templates->get($template)->params() as $param) {
            $value = $input[$param->name] ?? null;
            if ($param->internal || null === $value || '' === $value) {
                continue;
            }
            $params[$param->name] = 'int' === $param->type ? (int) $value : (string) $value;
        }

        return $params;
    }
}
