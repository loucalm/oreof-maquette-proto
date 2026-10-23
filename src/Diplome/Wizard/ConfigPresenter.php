<?php

declare(strict_types=1);

namespace App\Diplome\Wizard;

use App\Diplome\DurationLabeler;
use App\Diplome\Rule\RuleTemplateRegistry;
use App\Diplome\Spec\Bound;
use App\Diplome\Spec\EcType;
use App\Diplome\Spec\LevelSpec;
use App\Diplome\Spec\RuleSpec;
use App\Diplome\Spec\CanvasSpec;
use App\Diplome\Spec\VersionConfig;
use App\Formulaire\CanvasResolver;
use App\Diplome\WizardDraft;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Mise en mots d'une configuration de version (récapitulatif de l'assistant). */
final class ConfigPresenter
{
    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly RuleTemplateRegistry $templates,
        private readonly CanvasResolver $canvas,
        private readonly DurationLabeler $durations,
    ) {
    }

    /**
     * Sections lisibles : titre + lignes [libellé, valeur].
     *
     * @return list<array{title: string, rows: list<array{0: string, 1: string}>}>
     */
    public function sections(WizardDraft $draft): array
    {
        $info = $draft->informationsData();
        $config = $draft->config();

        $sections = [
            ['title' => $this->t('section.informations'), 'rows' => [
                [$this->t('recap.label'), (string) $info['label']],
                [$this->t('recap.sigle'), (string) $info['sigle']],
                [$this->t('recap.code_apogee'), $this->orDash($info['codeApogee'])],
                [$this->t('recap.description'), $this->orDash($info['description'])],
                [$this->t('recap.accredite'), $this->yesNo((bool) $info['accredite'])],
                [$this->t('recap.passage_cfvu'), $this->yesNo((bool) $info['passageCfvu'])],
                [$this->t('recap.annee'), $draft->anneeUniversitaire],
            ]],
            ['title' => $this->t('section.structure'), 'rows' => $this->structureRows($config)],
            ['title' => $this->t('section.forms'), 'rows' => $this->formsRows($config)],
            ['title' => $this->t('section.mccc'), 'rows' => $this->mcccRows($config)],
            ['title' => $this->t('section.bcc'), 'rows' => [
                [$this->t('recap.bcc_levels'), [] === $config->bccLevels ? '—' : implode(' › ', $config->bccLevels)],
            ]],
            ['title' => $this->t('section.admission'), 'rows' => $this->admissionRows($config)],
        ];

        return $sections;
    }

    /** Libellé en clair d'un chemin pointé de VersionConfig::toArray(). */
    public function pathLabel(string $path): string
    {
        $segments = explode('.', $path);
        $parts = [];
        foreach ($segments as $segment) {
            if (ctype_digit($segment)) {
                $parts[] = '#'.((int) $segment + 1);
                continue;
            }
            $id = 'diplome.wizard.path.'.$segment;
            $label = $this->translator->trans($id, [], 'diplome');
            $parts[] = $label === $id ? $segment : $label;
        }

        return implode(' › ', $parts);
    }

    public function boundLabel(?Bound $bound): string
    {
        if (null === $bound || (null === $bound->min && null === $bound->max)) {
            return '—';
        }
        $key = match (true) {
            $bound->isExact() => 'exact',
            null !== $bound->min && null !== $bound->max => 'range',
            null !== $bound->min => 'min',
            default => 'max',
        };

        return $this->translator->trans('diplome.wizard.bound.'.$key, ['%n%' => $bound->min, '%min%' => $bound->min, '%max%' => $bound->max], 'diplome');
    }

    /** @return list<array{0: string, 1: string}> */
    private function structureRows(VersionConfig $config): array
    {
        $s = $config->structure;
        $rows = [
            [$this->t('recap.duration'), $this->durations->describe($s->duration) ?? '—'],
            [$this->t('recap.parcours'), $this->yesNo($s->parcoursAllowed)],
        ];
        foreach ($s->levels as $i => $level) {
            $details = array_filter([
                null === $level->count ? null : $this->t('recap.level_count').' '.$this->boundLabel($level->count),
                null === $level->ects ? null : 'ECTS '.$this->boundLabel($level->ects),
                null === $level->ectsSum ? null : $this->t('recap.level_ects_sum').' '.$this->boundLabel($level->ectsSum),
                $level->locked ? $this->t('recap.level_locked') : null,
                LevelSpec::EC_COEFFICIENT === $level->ecLink ? $this->t('recap.level_coefficient') : null,
            ]);
            $rows[] = [$this->t('recap.level', ['%n%' => $i + 1]), $level->typeKey.([] === $details ? '' : ' ('.implode(', ', $details).')')];
        }
        $rows[] = [$this->t('recap.natures'), [] === $s->natures ? '—' : implode(', ', array_map(static fn (EcType $t): string => $t->label, $s->natures))];
        foreach ([] === $s->rules ? [null] : $s->rules as $rule) {
            $rows[] = [$this->t('recap.rules'), null === $rule ? '—' : $this->ruleLine($rule)];
        }

        return $rows;
    }

    private function ruleLine(RuleSpec $rule): string
    {
        $label = $rule->template;
        $values = [];
        if ($this->templates->has($rule->template)) {
            $template = $this->templates->get($rule->template);
            $label = $template->label();
            foreach ($template->params() as $param) {
                if (isset($rule->params[$param->name])) {
                    $values[] = $param->label.' : '.$rule->params[$param->name];
                }
            }
        }

        return $label.([] === $values ? '' : ' ('.implode(', ', $values).')').' — '.$this->t('recap.rule_scope', ['%level%' => $rule->scopeLevel]).('all' === $rule->scopePosition ? '' : ' '.$this->t('recap.rule_position', ['%position%' => $rule->scopePosition])).', '.$this->t('structure.severity.'.$rule->severity);
    }

    /** @return list<array{0: string, 1: string}> */
    private function formsRows(VersionConfig $config): array
    {
        $rows = [];
        $canvas = $this->canvas->forConfig($config);
        foreach (CanvasSpec::FICHES as $fiche) {
            $n = 0;
            foreach ($canvas->entries($fiche) as $entry) {
                $prefix = $this->t('formulaires.fiche.'.$fiche).' — '.($entry->hidden ? '' : ++$n.' | ').$this->canvas->label($fiche, $entry);
                $value = $entry->isBuiltin() ? $this->t('formulaires.builtin') : (string) $entry->form;
                $rows[] = [$prefix, $value.($entry->hidden ? ' ('.$this->t('path.hidden').')' : '')];
            }
        }

        return [] === $rows ? [[$this->t('recap.none'), '—']] : $rows;
    }

    /** @return list<array{0: string, 1: string}> */
    private function mcccRows(VersionConfig $config): array
    {
        $rows = [];
        foreach ($config->mccc as $type => $m) {
            $rows[] = [$type, $m['profile'] ?? $this->t('mccc.no_profile')];
        }
        foreach ($config->options as $option => $on) {
            $rows[] = [$this->pathLabel('options.'.$option), $this->yesNo($on)];
        }

        return $rows;
    }

    /** @return list<array{0: string, 1: string}> */
    private function admissionRows(VersionConfig $config): array
    {
        $rows = [];
        foreach ($config->admission as $plateforme => $years) {
            $rows[] = [$plateforme, implode(', ', $years)];
        }

        return [] === $rows ? [[$this->t('recap.none'), '—']] : $rows;
    }

    private function t(string $key, array $params = []): string
    {
        return $this->translator->trans('diplome.wizard.'.$key, $params, 'diplome');
    }

    private function yesNo(bool $v): string
    {
        return $this->t($v ? 'recap.yes' : 'recap.no');
    }

    private function orDash(mixed $v): string
    {
        return null === $v || '' === $v ? '—' : (string) $v;
    }
}
