<?php

declare(strict_types=1);

namespace App\Diplome\Spec;

/** Type d'élément constitutif proposé par un diplôme : une clé (ec, stage, sae…) et son libellé. */
final readonly class EcType
{
    public function __construct(public string $key, public string $label)
    {
    }

    /**
     * Accepte la forme courante (`{key, label}`) et l'ancienne (la clé seule, qui sert de libellé).
     *
     * @param array{key?: string, label?: string}|string $a
     */
    public static function fromArray(array|string $a): self
    {
        if (\is_string($a)) {
            return new self($a, $a);
        }
        $key = (string) ($a['key'] ?? '');

        return new self($key, (string) ($a['label'] ?? $key));
    }

    /** @return array{key: string, label: string} */
    public function toArray(): array
    {
        return ['key' => $this->key, 'label' => $this->label];
    }
}
