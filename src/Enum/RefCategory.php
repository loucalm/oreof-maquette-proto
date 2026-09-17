<?php

declare(strict_types=1);

namespace App\Enum;

/** Regroupement d'un référentiel sur la page d'administration. */
enum RefCategory: string
{
    case Libre = 'libre';
    case Entite = 'entite';

    public function label(): string
    {
        return match ($this) {
            self::Libre => 'Libre',
            self::Entite => 'Lier à des entités',
        };
    }
}
