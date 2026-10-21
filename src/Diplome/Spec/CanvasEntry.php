<?php

declare(strict_types=1);

namespace App\Diplome\Spec;

/**
 * Entrée d'une fiche du canevas : soit un formulaire de la bibliothèque (`form` = clé de
 * NodeType), soit un écran intégré (`builtin` = clé de BuiltinScreenInterface). `key`
 * range les données saisies (Formation::getParametre($key)) ; `label` null = libellé du
 * formulaire ou de l'écran.
 */
final readonly class CanvasEntry
{
    public function __construct(
        public string $key,
        public ?string $form,
        public ?string $builtin,
        public ?string $label,
        public bool $hidden,
    ) {
    }

    public function isBuiltin(): bool
    {
        return null !== $this->builtin;
    }

    /** @param array<string, mixed> $a */
    public static function fromArray(array $a): self
    {
        $string = static fn (string $k): ?string => isset($a[$k]) && '' !== (string) $a[$k] ? (string) $a[$k] : null;

        return new self((string) ($a['key'] ?? ''), $string('form'), $string('builtin'), $string('label'), (bool) ($a['hidden'] ?? false));
    }

    /** @return array{key: string, form: ?string, builtin: ?string, label: ?string, hidden: bool} */
    public function toArray(): array
    {
        return ['key' => $this->key, 'form' => $this->form, 'builtin' => $this->builtin, 'label' => $this->label, 'hidden' => $this->hidden];
    }
}
