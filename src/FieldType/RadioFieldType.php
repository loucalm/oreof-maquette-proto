<?php

declare(strict_types=1);

namespace App\FieldType;

final class RadioFieldType extends AbstractFieldType
{
    public function key(): string
    {
        return 'radio';
    }

    public function label(): string
    {
        return 'Choix unique (boutons radio)';
    }

    public function position(): int
    {
        return 50;
    }

    public function settings(): array
    {
        return [self::SETTING_REFERENTIEL, self::SETTING_OPTIONS];
    }
}
