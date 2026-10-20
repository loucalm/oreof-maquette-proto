<?php

declare(strict_types=1);

namespace App\Diplome\Wizard;

/** Étape réservée : remplie par une tâche ultérieure, affiche « à venir ». */
final class StructureStep extends AbstractWizardStep
{
    public function key(): string
    {
        return 'structure';
    }

    public function position(): int
    {
        return 40;
    }

    public function template(): string
    {
        return 'coming';
    }
}
