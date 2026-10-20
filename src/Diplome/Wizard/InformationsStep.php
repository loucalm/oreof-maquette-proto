<?php

declare(strict_types=1);

namespace App\Diplome\Wizard;

use App\Diplome\WizardDraft;
use App\Form\Diplome\InformationsType;

final class InformationsStep extends AbstractWizardStep
{
    public function key(): string
    {
        return 'informations';
    }

    public function position(): int
    {
        return 10;
    }

    public function needsInformations(): bool
    {
        return false;
    }

    public function formType(): string
    {
        return InformationsType::class;
    }

    public function initialData(WizardDraft $draft): array
    {
        return $draft->informationsData();
    }

    public function apply(WizardDraft $draft, mixed $formData): void
    {
        $draft->anneeUniversitaire = (string) $formData['anneeUniversitaire'];
        unset($formData['anneeUniversitaire']);
        $draft->informations = $formData;
        $draft->informationsValid = true;
    }

    public function template(): string
    {
        return 'informations';
    }
}
