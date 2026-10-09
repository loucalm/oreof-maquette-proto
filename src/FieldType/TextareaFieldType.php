<?php

declare(strict_types=1);

namespace App\FieldType;

final class TextareaFieldType extends AbstractFieldType
{
    public function key(): string
    {
        return 'textarea';
    }

    public function label(): string
    {
        return 'Texte long';
    }

    public function position(): int
    {
        return 20;
    }

    public function bounds(): ?string
    {
        return self::BOUNDS_LENGTH;
    }
}
