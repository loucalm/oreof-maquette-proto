<?php

declare(strict_types=1);

namespace App\Diplome\Spec;

/**
 * Canevas d'une version : la liste ordonnée des entrées de la fiche formation et de la
 * fiche parcours (cf. CanvasResolver pour le canevas effectif d'une formation).
 */
final readonly class CanvasSpec
{
    public const FORMATION = 'formation';
    public const PARCOURS = 'parcours';
    public const FICHES = [self::FORMATION, self::PARCOURS];

    /**
     * @param list<CanvasEntry> $formation
     * @param list<CanvasEntry> $parcours
     */
    public function __construct(
        public array $formation = [],
        public array $parcours = [],
    ) {
    }

    /** @return list<CanvasEntry> */
    public function entries(string $fiche): array
    {
        return self::PARCOURS === $fiche ? $this->parcours : $this->formation;
    }

    public function entry(string $fiche, string $key): ?CanvasEntry
    {
        foreach ($this->entries($fiche) as $entry) {
            if ($entry->key === $key) {
                return $entry;
            }
        }

        return null;
    }

    /** @param list<CanvasEntry> $entries */
    public function withEntries(string $fiche, array $entries): self
    {
        $entries = array_values($entries);

        return self::PARCOURS === $fiche ? new self($this->formation, $entries) : new self($entries, $this->parcours);
    }

    /** @param array<string, mixed> $a */
    public static function fromArray(array $a): self
    {
        $list = static fn (string $fiche): array => array_values(array_map(
            static fn (array $e) => CanvasEntry::fromArray($e),
            array_filter($a[$fiche] ?? [], 'is_array'),
        ));

        return new self($list(self::FORMATION), $list(self::PARCOURS));
    }

    /** @return array{formation: list<array<string, mixed>>, parcours: list<array<string, mixed>>} */
    public function toArray(): array
    {
        return [
            self::FORMATION => array_map(static fn (CanvasEntry $e) => $e->toArray(), $this->formation),
            self::PARCOURS => array_map(static fn (CanvasEntry $e) => $e->toArray(), $this->parcours),
        ];
    }
}
