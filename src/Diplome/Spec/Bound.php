<?php

declare(strict_types=1);

namespace App\Diplome\Spec;

/**
 * Borne d'une quantité : minimum, maximum, ou exactement (min = max).
 * Une borne absente (null) est ouverte de ce côté.
 */
final readonly class Bound
{
    public function __construct(public ?int $min, public ?int $max)
    {
    }

    public static function exactly(int $n): self
    {
        return new self($n, $n);
    }

    /** @param array{min?: int|null, max?: int|null}|null $a */
    public static function fromArray(?array $a): ?self
    {
        if (null === $a) {
            return null;
        }

        return new self(
            isset($a['min']) ? (int) $a['min'] : null,
            isset($a['max']) ? (int) $a['max'] : null,
        );
    }

    /**
     * Forme canonique : seules les bornes renseignées sont émises, dans l'ordre min puis max.
     *
     * @return array{min?: int, max?: int}
     */
    public function toArray(): array
    {
        $out = [];
        if (null !== $this->min) {
            $out['min'] = $this->min;
        }
        if (null !== $this->max) {
            $out['max'] = $this->max;
        }

        return $out;
    }

    public function isExact(): bool
    {
        return null !== $this->min && $this->min === $this->max;
    }
}
