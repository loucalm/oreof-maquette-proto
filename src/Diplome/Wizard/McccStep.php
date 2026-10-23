<?php

declare(strict_types=1);

namespace App\Diplome\Wizard;

use App\Diplome\WizardDraft;
use App\Form\Diplome\MccConfigType;
use App\Repository\MccTypeRepository;

/** Étape « MCCC » : types de MCCC autorisés dans la formation, chacun avec son profil de règles, et options. */
final class McccStep extends AbstractWizardStep
{
    public function __construct(private readonly MccTypeRepository $mccTypes)
    {
    }

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
        $types = [];
        foreach ($this->mccTypes->allOrdered() as $type) {
            $key = $type->getKey();
            $types[] = [
                'type' => $key,
                // libellés pour l'affichage, non soumis
                'label' => $type->getShortLabel().' — '.$type->getLabel(),
                'enabled' => isset($config->mccc[$key]),
                'profile' => $config->mccc[$key]['profile'] ?? null,
            ];
        }

        return ['types' => $types] + $config->options;
    }

    public function apply(WizardDraft $draft, mixed $formData): void
    {
        $mccc = [];
        foreach ($formData['types'] ?? [] as $row) {
            $key = (string) ($row['type'] ?? '');
            if ('' === $key || !($row['enabled'] ?? false)) {
                continue;
            }
            $mccc[$key] = ['profile' => '' === (string) ($row['profile'] ?? '') ? null : (string) $row['profile']];
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
