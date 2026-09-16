<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Un type d'ELP est-il porté par une saisie libre de l'utilisateur (structurel :
 * UE, EC…) ou son nom est-il entièrement calculé (temporel : Année, Semestre…,
 * jamais de libellé demandé côté éditeur, affichage = libellé du type + référence
 * numérotée, ex. « Année 1 ») ?
 */
enum NodeKind: string
{
    case Structurel = 'structurel';
    case Temporel = 'temporel';

    public function label(): string
    {
        return match ($this) {
            self::Structurel => 'Structurel (libellé saisi)',
            self::Temporel => 'Temporel (nom calculé)',
        };
    }
}
