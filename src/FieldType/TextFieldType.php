<?php

declare(strict_types=1);

namespace App\FieldType;

final class TextFieldType extends AbstractFieldType
{
    public function key(): string
    {
        return 'text';
    }

    public function label(): string
    {
        return 'Texte court';
    }

    public function position(): int
    {
        return 10;
    }

    public function bounds(): ?string
    {
        return self::BOUNDS_LENGTH;
    }
}
