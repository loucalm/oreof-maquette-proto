<?php

declare(strict_types=1);

namespace App\Diplome\Wizard;

use App\Diplome\WizardDraft;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Une étape de l'assistant « Nouveau diplôme ». Une classe par étape : en
 * ajouter une = écrire une classe, le registre (WizardStepRegistry) la trouve
 * seul. Le contrôleur ne fait que résoudre l'étape, traiter son formulaire,
 * appliquer les données au brouillon et rendre.
 */
#[AutoconfigureTag(self::TAG)]
interface WizardStepInterface
{
    public const TAG = 'app.diplome_wizard_step';

    /** Identifiant dans l'URL. */
    public function key(): string;

    /** Rang dans l'assistant (ordre croissant). */
    public function position(): int;

    /** Vrai si l'étape n'est accessible qu'une fois les informations valides. */
    public function needsInformations(): bool;

    /** @return class-string|null Type de formulaire, null pour une étape sans saisie. */
    public function formType(): ?string;

    public function initialData(WizardDraft $draft): mixed;

    /** Applique les données validées au brouillon. */
    public function apply(WizardDraft $draft, mixed $formData): void;

    /** Gabarit Twig (sous `diplome/wizard/`). */
    public function template(): string;

    /**
     * Variables supplémentaires du gabarit.
     *
     * @return array<string, mixed>
     */
    public function viewData(WizardDraft $draft): array;
}
