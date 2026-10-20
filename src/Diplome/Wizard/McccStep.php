<?php

declare(strict_types=1);

namespace App\Diplome\Wizard;

use App\Diplome\WizardDraft;
use App\Form\Diplome\MccConfigType;

/** Étape « MCCC » : types de MCCC autorisés et profil de règles par nature d'élément, options. */
final class McccStep extends AbstractWizardStep
{
    public function key(): string
    {
        return 'mccc';
    }

    public function position(): int
    {
        return 50;
    }

    public function editable(): bool
    {
        return true;
    }

    public function formType(): string
    {
        return MccConfigType::class;
    }

    public function initialData(WizardDraft $draft): array
    {
        $config = $draft->config();
        $natures = [];
        foreach ($config->structure->natures as $nature) {
            $natures[] = [
                'nature' => $nature,
                'types' => $config->mccc[$nature]['types'] ?? [],
                'profile' => $config->mccc[$nature]['profile'] ?? null,
            ];
        }

        return ['natures' => $natures] + $config->options;
    }

    public function apply(WizardDraft $draft, mixed $formData): void
    {
        $declared = $draft->config()->structure->natures;
        $mccc = [];
        foreach ($formData['natures'] ?? [] as $row) {
            $nature = (string) ($row['nature'] ?? '');
            $types = array_values(array_map('strval', $row['types'] ?? []));
            // seules les natures déclarées par la structure comptent ; une nature sans type n'est pas enregistrée
            if (!\in_array($nature, $declared, true) || [] === $types) {
                continue;
            }
            $mccc[$nature] = ['types' => $types, 'profile' => '' === (string) ($row['profile'] ?? '') ? null : (string) $row['profile']];
        }

        $config = $draft->configData;
        $config['mccc'] = $mccc;
        $config['options'] = [
            'quitus' => (bool) ($formData['quitus'] ?? false),
            'assiduite' => (bool) ($formData['assiduite'] ?? false),
            'sansNote' => (bool) ($formData['sansNote'] ?? false),
        ];
        $draft->configData = $config;
    }

    public function template(): string
    {
        return 'mccc';
    }
}
