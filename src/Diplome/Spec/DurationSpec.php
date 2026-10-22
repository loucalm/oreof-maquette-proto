<?php

declare(strict_types=1);

namespace App\Diplome\Spec;

/**
 * Durée d'un diplôme : le niveau de la chaîne qui la compte (`levelKey`, ex. année
 * ou semestre) et le nombre de nœuds de ce niveau admis (`bound`).
 */
final readonly class DurationSpec
{
    public function __construct(public string $levelKey, public Bound $bound)
    {
    }

    /**
     * Accepte la forme courante (`levelKey` + `bound`) et l'ancienne (une simple borne,
     * rattachée au premier niveau de la chaîne, `$defaultLevelKey`).
     *
     * @param array<string, mixed>|null $a
     */
    public static function fromArray(?array $a, string $defaultLevelKey): ?self
    {
        if (null === $a) {
            return null;
        }
        if (\array_key_exists('levelKey', $a)) {
            return new self((string) $a['levelKey'], Bound::fromArray($a['bound'] ?? null) ?? new Bound(null, null));
        }

        return new self($defaultLevelKey, Bound::fromArray($a) ?? new Bound(null, null));
    }

    /** @return array{levelKey: string, bound: array{min?: int, max?: int}} */
    public function toArray(): array
    {
        return ['levelKey' => $this->levelKey, 'bound' => $this->bound->toArray()];
    }
}
