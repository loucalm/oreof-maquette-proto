<?php

declare(strict_types=1);

namespace App\FieldType;

final class CompetenciesFieldType extends AbstractFieldType
{
    public function key(): string
    {
        return 'competencies';
    }

    public function label(): string
    {
        return 'Compétences (référentiel)';
    }

    public function position(): int
    {
        return 70;
    }

    public function viewTemplate(): string
    {
        return 'field_type/view/competencies.html.twig';
    }
}
