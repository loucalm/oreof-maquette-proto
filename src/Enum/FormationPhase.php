<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Phase du cycle de vie d'une formation. `Consolidation` = squelette (nom +
 * structure) préparé en avance par la composante pour l'année suivante, sans
 * contenu à ce stade ; `Construction` = phase normale de construction de
 * l'offre (comportement historique, valeur par défaut).
 */
enum FormationPhase: string
{
    case Consolidation = 'consolidation';
    case Construction = 'construction';

    public function label(): string
    {
        return match ($this) {
            self::Consolidation => 'Consolidation de l’offre',
            self::Construction => 'Construction',
        };
    }
}
