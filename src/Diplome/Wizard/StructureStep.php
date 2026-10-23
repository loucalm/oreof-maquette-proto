<?php

declare(strict_types=1);

namespace App\Diplome\Wizard;

use App\Diplome\Rule\RuleTemplateRegistry;
use App\Diplome\Spec\DurationMath;
use App\Diplome\Spec\DurationSpec;
use App\Diplome\Spec\EcType;
use App\Diplome\Spec\LevelSpec;
use App\Diplome\Spec\RuleSpec;
use App\Diplome\Spec\StructureSpec;
use App\Diplome\StructurePreview;
use App\Diplome\WizardDraft;
use App\Enum\NodeFamily;
use App\Form\Diplome\BoundType;
use App\Form\Diplome\EctsModeType;
use App\Form\Diplome\RuleSpecType;
use App\Form\Diplome\StructureType;
use App\Repository\NodeTypeRepository;

/** Étape « Structure » : niveaux, bornes, types d'EC et règles du type de diplôme. */
final class StructureStep extends AbstractWizardStep
{
    public function __construct(
        private readonly RuleTemplateRegistry $templates,
        private readonly StructurePreview $preview,
        private readonly NodeTypeRepository $nodeTypes,
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
            'natures' => array_map(static fn (EcType $t) => $t->toArray(), $s->natures),
            // lecture seule pour la validation (types d'EC retirés mais encore utilisés), non soumis
            'usage' => ['labels' => array_column(array_map(static fn (EcType $t) => $t->toArray(), $s->natures), 'label', 'key')],
            'levels' => array_map(static fn (LevelSpec $l, int $i) => [
                'position' => (string) $i,
                'typeKey' => $l->typeKey, 'count' => $l->count, 'startAt' => $l->startAt, 'locked' => $l->locked, 'ecLink' => $l->ecLink,
                // un seul réglage ECTS dans le panneau : somme attendue, sinon borne par nœud, sinon libres
                'ectsMode' => null !== $l->ectsSum ? EctsModeType::SUM : (null !== $l->ects ? EctsModeType::NODE : EctsModeType::FREE),
                'ectsBound' => $l->ectsSum ?? $l->ects,
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
        $levels = array_map(static function (int $i, int $at) use ($rows): LevelSpec {
            $row = $rows[$i];
            $mode = $row['ectsMode'] ?? EctsModeType::FREE;
            $ects = $row['ectsBound'] ?? null;

            return new LevelSpec(
                (string) $row['typeKey'],
                // le nombre du premier niveau n'est pas saisi : il découle de la durée
                0 === $at ? null : $row['count'],
                EctsModeType::NODE === $mode ? $ects : null,
                EctsModeType::SUM === $mode ? $ects : null,
                max(1, (int) ($row['startAt'] ?? 1)),
                (bool) ($row['locked'] ?? false),
                (string) ($row['ecLink'] ?? LevelSpec::EC_CONTAINS),
            );
        }, $order, array_keys($order));
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
        // types d'EC : la clé d'un type ajouté a été dérivée de son libellé (EcTypeType)
        $natures = array_map(static fn (array $t) => new EcType((string) $t['key'], trim((string) $t['label'])), array_values($formData['natures'] ?? []));
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

        $first = DurationMath::firstLevelBound($draft->config()->structure);

        return [
            'preview' => $roots,
            'previewError' => $previewError,
            'nodeTypes' => $this->structuralTypes(),
            // résumé du premier niveau au chargement (recalculé en direct par structure_tree_controller.js)
            'firstCount' => null === $first ? null : BoundType::viewData($first),
        ];
    }

    /**
     * Types de nœud qui peuvent former un niveau, dans l'ordre : ce que l'arbre doit savoir d'eux, sans tester leur clé.
     *
     * @return array<string, array{key: string, label: string, inSentence: string, temporal: bool, element: bool}>
     */
    private function structuralTypes(): array
    {
        $types = [];
        foreach ($this->nodeTypes->findAllOrdered() as $type) {
            if (NodeFamily::Structural === $type->getFamily() && !$type->isVariant()) {
                $types[$type->getKey()] = [
                    'key' => $type->getKey(),
                    'label' => $type->getLabel(),
                    'inSentence' => self::inSentence($type->getLabel()),
                    'temporal' => $type->isTemporel(),
                    'element' => $type->isElementLevel(),
                ];
            }
        }

        return $types;
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

    /** Libellé dans une phrase : « Semestre » → « semestre », mais « UE » reste « UE » (même règle que structure_tree_controller.js). */
    private static function inSentence(string $label): string
    {
        $second = mb_substr($label, 1, 1);

        return mb_strlen($label) > 1 && $second === mb_strtolower($second) ? mb_strtolower(mb_substr($label, 0, 1)).mb_substr($label, 1) : $label;
    }
}
