<?php

declare(strict_types=1);

namespace App\FieldType;

final class CheckboxFieldType extends AbstractFieldType
{
    public function key(): string
    {
        return 'checkbox';
    }

    public function label(): string
    {
        return 'Case à cocher (choix multiple)';
    }

    public function position(): int
    {
        return 60;
    }

    public function settings(): array
    {
        return [self::SETTING_REFERENTIEL, self::SETTING_OPTIONS, self::SETTING_QUICK_ADD];
    }

    public function bounds(): ?string
    {
        return self::BOUNDS_COUNT;
    }
}
