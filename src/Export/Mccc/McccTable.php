<?php

declare(strict_types=1);

namespace App\Export\Mccc;

/**
 * Tableau des MCCC d'une formation (ou d'un de ses parcours), prêt à être écrit
 * dans un classeur : une feuille par nœud du niveau choisi (année, semestre…).
 *
 * - `info` : en-tête [libellé, valeur] (type de formation, mention, parcours…) ;
 * - `groupLevels` : niveaux intermédiaires repris en colonnes (ex. Semestre, UE),
 *   `withGroupLabels` : ceux dont l'intitulé est renseigné quelque part (sinon la colonne d'intitulé est omise) ;
 * - `mcccColumns` : clé MccType → libellé, une colonne par type autorisé.
 */
final readonly class McccTable
{
    /**
     * @param list<array{0: string, 1: string}> $info
     * @param list<array{key: string, label: string}> $groupLevels
     * @param array<string, bool>               $withGroupLabels clé de niveau → colonne d'intitulé présente
     * @param array<string, string>             $mcccColumns
     * @param list<McccSheet>                   $sheets
     */
    public function __construct(
        public string $title,
        public array $info,
        public array $groupLevels,
        public array $withGroupLabels,
        public array $mcccColumns,
        public array $sheets,
        public string $fileName,
    ) {
    }
}
