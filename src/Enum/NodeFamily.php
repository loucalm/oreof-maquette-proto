<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Grande famille d'un type de nœud. Détermine surtout le rendu et où le type
 * peut apparaître ; les règles fines vivent dans NodeType (données, pas code).
 */
enum NodeFamily: string
{
    /** Structure pédagogique : parcours, année, semestre, UE, EC… */
    case Structural = 'structural';

    /** Référentiel de compétences : BCC, bloc, compétence. */
    case Competence = 'competence';

    /** Nœud de configuration hors pédagogie : présentation, localisation… */
    case Parameter = 'parameter';

    public function label(): string
    {
        return match ($this) {
            self::Structural => 'Structure pédagogique',
            self::Competence => 'Compétences',
            self::Parameter => 'Paramètre',
        };
    }
}
