<?php

declare(strict_types=1);

namespace App\Diplome\Wizard;

use App\Diplome\WizardDraft;
use App\Form\Diplome\FormsType;

final class FormsStep extends AbstractWizardStep
{
    public function key(): string
    {
        return 'formulaires';
    }

    public function position(): int
    {
        return 30;
    }

    public function editable(): bool
    {
        return true;
    }

    public function formType(): string
    {
        return FormsType::class;
    }

    public function initialData(WizardDraft $draft): array
    {
        $forms = $draft->config()->forms;
        $data = [];
        foreach (FormsType::SLOTS as $slot) {
            $data[FormsType::fieldName($slot)] = $forms[$slot] ?? null;
        }

        return $data;
    }

    public function apply(WizardDraft $draft, mixed $formData): void
    {
        $config = $draft->configData;
        // les emplacements que le formulaire ne propose pas sont conservés
        $forms = $config['forms'] ?? [];
        foreach (FormsType::SLOTS as $slot) {
            $value = $formData[FormsType::fieldName($slot)] ?? null;
            if (null !== $value && '' !== $value) {
                $forms[$slot] = (string) $value;
            } else {
                unset($forms[$slot]);
            }
        }
        $config['forms'] = $forms;
        $draft->configData = $config;
    }

    public function template(): string
    {
        return 'forms';
    }
}
