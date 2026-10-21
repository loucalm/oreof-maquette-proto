<?php

declare(strict_types=1);

namespace App\Formulaire\Builtin;

use App\Entity\Formation;
use App\Maquette\Doc\TreeNode;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Écran intégré d'une fiche (BCC, configuration de la structure, ramification…) : une
 * entrée du canevas dont le contenu est un écran dédié de l'application, et non un
 * formulaire de la bibliothèque. Une classe par écran : en ajouter un = écrire une
 * classe, le registre (BuiltinScreenRegistry) la trouve seul.
 */
#[AutoconfigureTag(self::TAG)]
interface BuiltinScreenInterface
{
    public const TAG = 'app.builtin_screen';

    /** Clé de l'entrée dans le canevas, unique dans sa fiche. */
    public function key(): string;

    /** Libellé affiché (déjà traduit). */
    public function label(): string;

    /** Fiche qui porte l'écran : CanvasSpec::FORMATION ou CanvasSpec::PARCOURS. */
    public function fiche(): string;

    /** Vrai si l'admin peut masquer l'entrée dans le canevas. */
    public function hideable(): bool;

    /** Rang dans le canevas par défaut (comparé à NodeType::getDefaultEntryPosition()). */
    public function defaultPosition(): int;

    /** Vrai si l'écran s'affiche pour cette formation (ex. avec ou sans parcours). */
    public function appliesTo(Formation $formation): bool;

    public function url(Formation $formation, ?TreeNode $parcours): string;

    /** Pastille de statut (`empty`, `incomplete`, `ok`, `error`), ou null pour aucune. */
    public function status(Formation $formation, ?TreeNode $parcours): ?string;

    /**
     * Vrai si les paramètres de requête de l'éditeur désignent cet écran (panneau ouvert).
     *
     * @param array<string, mixed> $query
     */
    public function isCurrent(array $query): bool;
}
