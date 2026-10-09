<?php

declare(strict_types=1);

namespace App\FieldType;

final class ChoiceFieldType extends AbstractFieldType
{
    public function key(): string
    {
        return 'choice';
    }

    public function label(): string
    {
        return 'Liste de choix';
    }

    public function position(): int
    {
        return 40;
    }

    public function settings(): array
    {
        return [self::SETTING_REFERENTIEL, self::SETTING_OPTIONS, self::SETTING_ALLOW_EXTRA, self::SETTING_QUICK_ADD];
    }
}
