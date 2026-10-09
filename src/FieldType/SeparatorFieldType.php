<?php

declare(strict_types=1);

namespace App\FieldType;

final class SeparatorFieldType extends AbstractFieldType
{
    public function key(): string
    {
        return 'separator';
    }

    public function label(): string
    {
        return 'Séparateur (ligne d’organisation)';
    }

    public function position(): int
    {
        return 100;
    }

    public function isInput(): bool
    {
        return false;
    }

    public function isFilled(mixed $value): bool
    {
        return true;
    }
}
