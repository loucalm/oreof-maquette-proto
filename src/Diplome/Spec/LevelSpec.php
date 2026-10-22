<?php

declare(strict_types=1);

namespace App\Diplome\Spec;

/**
 * Un niveau de la chaîne de structure d'un diplôme (année, semestre, UE, EC…).
 *
 * - `count` : nombre de nœuds de ce niveau par parent ;
 * - `ects` : ECTS de chaque nœud ;
 * - `ectsSum` : somme des ECTS des enfants attendue sur chaque nœud de ce niveau ;
 * - `startAt` : premier indice de numérotation (LP : semestre à 5) ;
 * - `locked` : le niveau est figé (non modifiable dans les formations) ;
 * - `ecLink` : comment les éléments se rattachent au niveau (`contains` ou `coefficient`).
 */
final readonly class LevelSpec
{
    public const EC_CONTAINS = 'contains';
    public const EC_COEFFICIENT = 'coefficient';

    public function __construct(
        public string $typeKey,
        public ?Bound $count = null,
        public ?Bound $ects = null,
        public ?Bound $ectsSum = null,
        public int $startAt = 1,
        public bool $locked = false,
        public string $ecLink = self::EC_CONTAINS,
    ) {
    }

    /** @param array<string, mixed> $a */
    public static function fromArray(array $a): self
    {
        return new self(
            (string) ($a['typeKey'] ?? ''),
            Bound::fromArray($a['count'] ?? null),
            Bound::fromArray($a['ects'] ?? null),
            Bound::fromArray($a['ectsSum'] ?? null),
            (int) ($a['startAt'] ?? 1),
            (bool) ($a['locked'] ?? false),
            self::EC_COEFFICIENT === ($a['ecLink'] ?? null) ? self::EC_COEFFICIENT : self::EC_CONTAINS,
        );
    }

    /**
     * Forme canonique : typeKey, count, ects, ectsSum (null si absent), startAt, locked, ecLink.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'typeKey' => $this->typeKey,
            'count' => $this->count?->toArray(),
            'ects' => $this->ects?->toArray(),
            'ectsSum' => $this->ectsSum?->toArray(),
            'startAt' => $this->startAt,
            'locked' => $this->locked,
            'ecLink' => $this->ecLink,
        ];
    }
}
