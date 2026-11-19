<?php

declare(strict_types=1);

namespace App\FicheMatiere;

/**
 * Valeur effective d'un EC pour une clé (`hours`, `ects`, `mccc`, `competencies`).
 *
 * `source` dit d'où vient réellement la valeur (`fiche`, `ec`, `parent`, `porteur`) : quand la source
 * demandée ne peut pas répondre, c'est `ec` (valeur propre) et `warning` explique pourquoi.
 */
final readonly class ResolvedValue
{
    public function __construct(
        public mixed $value,
        public string $source,
        public bool $locked = false,
        public ?string $warning = null,
    ) {
    }
}
