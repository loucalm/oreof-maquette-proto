<?php

declare(strict_types=1);

namespace App\Diplome\Wizard;

use App\Diplome\Rule\RuleTemplateRegistry;
use App\Diplome\Spec\DurationSpec;
use App\Diplome\Spec\EcType;
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
            'levels' => array_map(static fn (LevelSpec $l, int $i) => [
                'position' => (string) $i,
                'typeKey' => $l->typeKey, 'count' => $l->count, 'ects' => $l->ects, 'ectsSum' => $l->ectsSum, 'startAt' => $l->startAt,
                'locked' => $l->locked, 'ecLink' => $l->ecLink,
            ], $s->levels, array_keys($s->levels)),
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
        // ordre de la chaîne : le rang soumis, à rang égal l'ordre des lignes
        $rows = array_values($formData['levels'] ?? []);
        $order = array_keys($rows);
        usort($order, static fn (int $a, int $b) => [(int) ($rows[$a]['position'] ?? $a), $a] <=> [(int) ($rows[$b]['position'] ?? $b), $b]);
        $levels = array_map(
            static fn (int $i) => new LevelSpec((string) $rows[$i]['typeKey'], $rows[$i]['count'], $rows[$i]['ects'], $rows[$i]['ectsSum'], max(1, (int) ($rows[$i]['startAt'] ?? 1)), (bool) ($rows[$i]['locked'] ?? false), (string) ($rows[$i]['ecLink'] ?? LevelSpec::EC_CONTAINS)),
            $order,
        );
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

        // les paramètres internes ne sont pas saisis : on les reprend de la règle de même uid
        $previous = [];
        foreach ($draft->config()->structure->rules as $existing) {
            $previous[$existing->uid] = $existing->params;
        }
        $rules = [];
        foreach ($formData['rules'] ?? [] as $r) {
            $rules[] = new RuleSpec(
                '' !== (string) ($r['uid'] ?? '') ? (string) $r['uid'] : 'rule-'.bin2hex(random_bytes(4)),
                (string) $r['template'],
                $this->params((string) $r['template'], $r[RuleSpecType::PARAMS_PREFIX.$r['template']] ?? [], $previous[(string) ($r['uid'] ?? '')] ?? []),
                (string) $r['scopeLevel'],
                (string) $r['severity'],
            );
        }

        // durée : à défaut de niveau choisi, le premier de la chaîne ; sans borne, pas de durée imposée
        $bound = $formData['duration']['bound'] ?? null;
        $duration = null === $bound ? null : new DurationSpec('' !== (string) ($formData['duration']['levelKey'] ?? '') ? (string) $formData['duration']['levelKey'] : ($chain[0] ?? ''), $bound);
        // interim (écran refait plus tard) : les natures saisies en texte deviennent des types d'EC dont le libellé est la clé
        $natures = array_map(static fn (string $key) => new EcType($key, $key), array_values($formData['natures'] ?? []));
        $structure = new StructureSpec($levels, (bool) $formData['parcoursAllowed'], $duration, $natures, $imposed, $rules);
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
     * Paramètres saisis, restreints à ceux que le gabarit déclare (plus les internes déjà enregistrés, typés.
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed> $existing params de la règle déjà enregistrée (source des paramètres internes)
     *
     * @return array<string, mixed>
     */
    private function params(string $template, array $input, array $existing): array
    {
        $params = [];
        foreach ($this->templates->get($template)->params() as $param) {
            $value = $param->internal ? ($existing[$param->name] ?? null) : ($input[$param->name] ?? null);
            if (null === $value || '' === $value) {
                continue;
            }
            $params[$param->name] = 'int' === $param->type ? (int) $value : (string) $value;
        }

        return $params;
    }
}
