<?php

declare(strict_types=1);

namespace App\Diplome\Check;

use App\Entity\Formation;

/** Une modification de la maquette créerait des écarts bloquants non couverts par une dérogation. */
final class StructureViolationException extends \RuntimeException
{
    /** @param non-empty-list<StructureViolation> $violations */
    public function __construct(
        public readonly Formation $formation,
        public readonly array $violations,
    ) {
        parent::__construct($violations[0]->message);
    }
}
