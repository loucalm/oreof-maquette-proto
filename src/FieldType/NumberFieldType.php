<?php

declare(strict_types=1);

namespace App\FieldType;

final class NumberFieldType extends AbstractFieldType
{
    public function key(): string
    {
        return 'number';
    }

    public function label(): string
    {
        return 'Nombre';
    }

    public function position(): int
    {
        return 30;
    }

    public function bounds(): ?string
    {
        return self::BOUNDS_VALUE;
    }
}
