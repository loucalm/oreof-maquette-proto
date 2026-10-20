<?php

declare(strict_types=1);

namespace App\Diplome\Wizard;

use App\Diplome\WizardDraft;

/** Valeurs par défaut : étape sans formulaire, accessible après les informations. */
abstract class AbstractWizardStep implements WizardStepInterface
{
    public function needsInformations(): bool
    {
        return true;
    }

    public function formType(): ?string
    {
        return null;
    }

    public function initialData(WizardDraft $draft): mixed
    {
        return null;
    }

    public function apply(WizardDraft $draft, mixed $formData): void
    {
    }

    public function viewData(WizardDraft $draft): array
    {
        return [];
    }
}
