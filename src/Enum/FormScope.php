<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Portée d'un formulaire : sur quel objet la saisie est-elle rattachée ?
 */
enum FormScope: string
{
    /** Formulaire de niveau formation. */
    case Formation = 'formation';

    /** Formulaire de niveau parcours. */
    case Parcours = 'parcours';

    /** Formulaire portant sur un élément de structure. */
    case Element = 'element';

    public function label(): string
    {
        return match ($this) {
            self::Formation => 'Formation',
            self::Parcours => 'Parcours',
            self::Element => 'Élément',
        };
    }
}
