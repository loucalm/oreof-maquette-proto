<?php

declare(strict_types=1);

namespace App\FieldType;

final class McccFieldType extends AbstractFieldType
{
    public function key(): string
    {
        return 'mccc';
    }

    public function label(): string
    {
        return 'MCCC (type de contrôle)';
    }

    public function position(): int
    {
        return 90;
    }

    public function isSystem(): bool
    {
        return true;
    }
}
