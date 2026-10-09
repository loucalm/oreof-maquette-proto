<?php

declare(strict_types=1);

namespace App\FieldType;

use App\Maquette\AttributeCatalog;

final class HoursFieldType extends AbstractFieldType
{
    public function key(): string
    {
        return 'hours';
    }

    public function label(): string
    {
        return 'Volume horaire (présentiel / distanciel / TE)';
    }

    public function position(): int
    {
        return 80;
    }

    public function isSystem(): bool
    {
        return true;
    }

    public function isFilled(mixed $value): bool
    {
        return AttributeCatalog::hoursProvided($value);
    }
}
