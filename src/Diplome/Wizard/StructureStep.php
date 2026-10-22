<?php

declare(strict_types=1);

namespace App\Diplome\Wizard;

use App\Diplome\Rule\RuleTemplateRegistry;
use App\Diplome\Spec\DurationSpec;
use App\Diplome\Spec\EcType;
use App\Diplome\Spec\LevelSpec;
use App\Diplome\Spec\RuleSpec;
use App\Diplome\Spec\StructureSpec;
use App\Diplome\StructurePreview;
use App\Diplome\WizardDraft;
use App\Form\Diplome\RuleSpecType;
use App\Form\Diplome\StructureType;

/** Étape « Structure » : niveaux, bornes, types d'EC et règles du type de diplôme. */
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

    public function editable(): bool
    {
        return true;
    }

    public function formType(): string
    {
        return StructureType::class;
    }

    public function initialData(WizardDraft $draft): array
    {
        $s = $draft->config()->structure;

        return [
            'duration' => ['levelKey' => $s->duration?->levelKey, 'bound' => $s->duration?->bound],
            'parcoursAllowed' => $s->parcoursAllowed,
            'natures' => $s->natureKeys(),
            // lecture seule pour la validation (types d'EC retirés mais encore utilisés), non soumis
            'usage' => ['labels' => array_column(array_map(static fn (EcType $t) => $t->toArray(), $s->natures), 'label', 'key'), 'mccc' => array_keys($draft->config()->mccc)],
            'levels' => array_map(static fn (LevelSpec $l, int $i) => [
                'position' => (string) $i,
                'typeKey' => $l->typeKey, 'count' => $l->count, 'ects' => $l->ects, 'ectsSum' => $l->ectsSum, 'startAt' => $l->startAt,
                'locked' => $l->locked, 'ecLink' => $l->ecLink,
            ], $s->levels, array_keys($s->levels)),
            'rules' => array_map(fn (RuleSpec $r) => [
                'uid' => $r->uid, 'template' => $r->template, 'scopeLevel' => $r->scopeLevel, 'scopePosition' => $r->scopePosition, 'severity' => $r->severity,
                RuleSpecType::PARAMS_PREFIX.$r->template => $r->params,
            ], $s->rules),
        ];
    }

    public function apply(WizardDraft $draft, mixed $formData): void
    {
        // ordre de la chaîne : le rang soumis, à rang égal l'ordre des lignes
        $rows = array_values($formData['levels'] ?? []);
        $order = array_keys($rows);
        usort($order, static fn (int $a, int $b) => [(int) ($rows[$a]['position'] ?? $a), $a] <=> [(int) ($rows[$b]['position'] ?? $b), $b]);
        $levels = array_map(
            static fn (int $i) => new LevelSpec((string) $rows[$i]['typeKey'], $rows[$i]['count'], $rows[$i]['ects'], $rows[$i]['ectsSum'], max(1, (int) ($rows[$i]['startAt'] ?? 1)), (bool) ($rows[$i]['locked'] ?? false), (string) ($rows[$i]['ecLink'] ?? LevelSpec::EC_CONTAINS)),
            $order,
        );
        $chain = array_map(static fn (LevelSpec $l) => $l->typeKey, $levels);

        $rules = [];
        foreach ($formData['rules'] ?? [] as $r) {
            $rules[] = new RuleSpec(
                '' !== (string) ($r['uid'] ?? '') ? (string) $r['uid'] : 'rule-'.bin2hex(random_bytes(4)),
                (string) $r['template'],
                $this->params((string) $r['template'], $r[RuleSpecType::PARAMS_PREFIX.$r['template']] ?? []),
                (string) $r['scopeLevel'],
                (string) $r['severity'],
                '' !== (string) ($r['scopePosition'] ?? '') ? (string) $r['scopePosition'] : 'all',
            );
        }

        // durée : à défaut de niveau choisi, le premier de la chaîne ; sans borne, pas de durée fixée
        $bound = $formData['duration']['bound'] ?? null;
        $duration = null === $bound ? null : new DurationSpec('' !== (string) ($formData['duration']['levelKey'] ?? '') ? (string) $formData['duration']['levelKey'] : ($chain[0] ?? ''), $bound);
        // interim (écran refait plus tard) : les natures saisies en texte deviennent des types d'EC ; un type déjà déclaré
        // garde son libellé, un nouveau prend sa clé pour libellé
        $labels = [];
        foreach ($draft->config()->structure->natures as $type) {
            $labels[$type->key] = $type->label;
        }
        $natures = array_map(static fn (string $key) => new EcType($key, $labels[$key] ?? $key), array_values($formData['natures'] ?? []));
        $structure = new StructureSpec($levels, (bool) $formData['parcoursAllowed'], $duration, $natures, $rules);
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
     * Paramètres saisis, restreints à ceux que le gabarit déclare, typés.
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
            if (null === $value || '' === $value) {
                continue;
            }
            $params[$param->name] = 'int' === $param->type ? (int) $value : (string) $value;
        }

        return $params;
    }
}
