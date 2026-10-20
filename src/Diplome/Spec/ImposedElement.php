<?php

declare(strict_types=1);

namespace App\Diplome\Spec;

/**
 * Élément imposé par le diplôme (ex. stage en dernier semestre).
 *
 * `position` : `first`, `last`, `all`, ou un indice « n » (1-based) parmi les
 * nœuds de `parentLevel`. `countOverride` surcharge la borne du niveau enfant
 * à cette position.
 */
final readonly class ImposedElement
{
    /**
     * @param list<string> $locked
     */
    public function __construct(
        public string $uid,
        public string $typeKey,
        public string $label,
        public string $parentLevel,
        public string $position,
        public ?string $nature = null,
        public array $locked = [],
        public ?Bound $countOverride = null,
    ) {
    }

    /** @param array<string, mixed> $a */
    public static function fromArray(array $a): self
    {
        return new self(
            (string) ($a['uid'] ?? ''),
            (string) ($a['typeKey'] ?? ''),
            (string) ($a['label'] ?? ''),
            (string) ($a['parentLevel'] ?? ''),
            (string) ($a['position'] ?? 'all'),
            isset($a['nature']) ? (string) $a['nature'] : null,
            array_values(array_map('strval', $a['locked'] ?? [])),
            Bound::fromArray($a['countOverride'] ?? null),
        );
    }

    /**
     * Forme canonique : uid, typeKey, label, parentLevel, position, nature, locked, countOverride.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'uid' => $this->uid,
            'typeKey' => $this->typeKey,
            'label' => $this->label,
            'parentLevel' => $this->parentLevel,
            'position' => $this->position,
            'nature' => $this->nature,
            'locked' => $this->locked,
            'countOverride' => $this->countOverride?->toArray(),
        ];
    }
}
