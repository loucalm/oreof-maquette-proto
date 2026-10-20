<?php

declare(strict_types=1);

namespace App\Diplome\Wizard;

/** Étape réservée : remplie par une tâche ultérieure, affiche « à venir ». */
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

    public function template(): string
    {
        return 'coming';
    }
}
