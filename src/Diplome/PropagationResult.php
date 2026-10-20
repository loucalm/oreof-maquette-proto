<?php

declare(strict_types=1);

namespace App\Diplome;

use App\Entity\Formation;

/** Bilan d'une propagation d'éléments imposés à toutes les formations d'une version. */
final readonly class PropagationResult
{
    /** @param list<array{formation: Formation, message: string}> $refused formations dont l'enregistrement a été refusé par le garde */
    public function __construct(public int $touched, public array $refused)
    {
    }
}
