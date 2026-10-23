<?php

declare(strict_types=1);

namespace App\Diplome\Wizard;

use App\Diplome\WizardDraft;
use App\Form\Diplome\AdmissionConfigType;
use App\Repository\ReferentielRepository;

/**
 * Étape « Admission » : plateformes de candidature du diplôme (Parcoursup, MonMaster…) et
 * années où l'on y candidate. Elles s'affichent dans la gestion de l'offre.
 */
final class AdmissionStep extends AbstractWizardStep
{
    public const REFERENTIEL = 'plateformes_admission';

    public function __construct(private readonly ReferentielRepository $referentiels)
    {
    }

    public function key(): string
    {
        return 'admission';
    }

    public function position(): int
    {
        return 65;
    }

    public function editable(): bool
    {
        return true;
    }

    public function formType(): string
    {
        return AdmissionConfigType::class;
    }

    public function initialData(WizardDraft $draft): array
    {
        $admission = $draft->config()->admission;
        // plateformes du référentiel, puis celles encore configurées mais retirées du référentiel
        $labels = $this->referentiels->values(self::REFERENTIEL) + array_combine(array_keys($admission), array_keys($admission));
        $rows = [];
        foreach ($labels as $key => $label) {
            $rows[] = [
                'key' => (string) $key,
                'label' => $label,
                'enabled' => isset($admission[$key]),
                'annees' => implode(', ', $admission[$key] ?? []),
            ];
        }

        return ['plateformes' => $rows];
    }

    public function apply(WizardDraft $draft, mixed $formData): void
    {
        $admission = [];
        foreach ($formData['plateformes'] ?? [] as $row) {
            $key = (string) ($row['key'] ?? '');
            $years = array_values(array_unique(array_filter(array_map('intval', preg_split('/\s*,\s*/', trim((string) ($row['annees'] ?? ''))) ?: []))));
            // une plateforme retenue sans année concerne la première année
            if ('' !== $key && ($row['enabled'] ?? false)) {
                $admission[$key] = [] === $years ? [1] : $years;
            }
        }

        $config = $draft->configData;
        $config['admission'] = $admission;
        $draft->configData = $config;
    }

    public function template(): string
    {
        return 'admission';
    }
}
