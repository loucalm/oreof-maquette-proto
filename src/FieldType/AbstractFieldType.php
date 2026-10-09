<?php

declare(strict_types=1);

namespace App\FieldType;

use App\Entity\FieldDef;

/** Comportements par défaut : champ saisissable, sans réglage ni borne. */
abstract class AbstractFieldType implements FieldTypeInterface
{
    public function settings(): array
    {
        return [];
    }

    public function bounds(): ?string
    {
        return null;
    }

    public function isSystem(): bool
    {
        return false;
    }

    public function isInput(): bool
    {
        return true;
    }

    public function isFilled(mixed $value): bool
    {
        return !(null === $value || '' === $value || [] === $value);
    }

    public function violations(mixed $value, FieldDef $def): array
    {
        $measure = $this->measure($value);
        if (null === $measure) {
            return [];
        }

        $unit = match ($this->bounds()) {
            self::BOUNDS_LENGTH => ' caractères',
            self::BOUNDS_COUNT => ' choix',
            default => '',
        };
        $out = [];
        if (null !== $def->getMin() && $measure < $def->getMin()) {
            $out[] = sprintf('%s : au moins %s%s (actuellement %s).', $def->getLabel(), self::fmt($def->getMin()), $unit, self::fmt($measure));
        }
        if (null !== $def->getMax() && $measure > $def->getMax()) {
            $out[] = sprintf('%s : au plus %s%s (actuellement %s).', $def->getLabel(), self::fmt($def->getMax()), $unit, self::fmt($measure));
        }

        return $out;
    }

    /** Grandeur comparée aux bornes (valeur, longueur ou nombre de choix) ; null = pas de bornes. */
    protected function measure(mixed $value): ?float
    {
        return match ($this->bounds()) {
            self::BOUNDS_VALUE => is_numeric($value) ? (float) $value : null,
            self::BOUNDS_LENGTH => \is_string($value) ? (float) mb_strlen(trim($value)) : null,
            self::BOUNDS_COUNT => \is_array($value) ? (float) \count($value) : null,
            default => null,
        };
    }

    private static function fmt(float $n): string
    {
        return rtrim(rtrim(number_format($n, 2, ',', ''), '0'), ',');
    }
}
