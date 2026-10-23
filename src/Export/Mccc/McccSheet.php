<?php

declare(strict_types=1);

namespace App\Export\Mccc;

/**
 * Une feuille du tableau MCCC : le nœud qui la porte (« Année 1 », « Semestre 3 »)
 * et ses lignes, dans l'ordre.
 *
 * Ligne « element » : groups (clé de niveau → [réf, intitulé]), ref, code, label, kind (type d'élément),
 * nature, competencies, ects, hours (presCm…distTp, te), mccc (clé MccType → texte).
 * Ligne « total » : label, ects, hours.
 */
final readonly class McccSheet
{
    /** @param list<array<string, mixed>> $lines */
    public function __construct(
        public string $title,
        public string $subtitle,
        public array $lines,
    ) {
    }
}
