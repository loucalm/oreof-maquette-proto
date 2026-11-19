<?php

declare(strict_types=1);

namespace App\FicheMatiere;

/** Lien entre une fiche matière et le parcours d'un EC qui voudrait l'utiliser. */
enum FicheRelation: string
{
    case Porteur = 'porteur';
    case Ancetre = 'ancetre';
    case Mutualisee = 'mutualisee';
    case HorsDiplome = 'hors_diplome';
    case Interdite = 'interdite';
}
