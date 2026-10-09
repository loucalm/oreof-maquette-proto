<?php

declare(strict_types=1);

namespace App\FieldType;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Catalogue des types de saisie, alimenté par les classes FieldTypeInterface
 * (tag `app.field_type`). Source unique pour la liste de l'éditeur de champ,
 * les réglages qu'il affiche et la validation des valeurs.
 */
final class FieldTypeRegistry
{
    /** @var array<string, FieldTypeInterface> */
    private array $types = [];

    /** @param iterable<FieldTypeInterface> $types */
    public function __construct(#[AutowireIterator(FieldTypeInterface::TAG)] iterable $types)
    {
        $list = [...$types];
        usort($list, static fn (FieldTypeInterface $a, FieldTypeInterface $b) => $a->position() <=> $b->position());
        foreach ($list as $type) {
            $this->types[$type->key()] = $type;
        }
    }

    public function has(string $key): bool
    {
        return isset($this->types[$key]);
    }

    /** Type inconnu (donnée ancienne) : traité comme un texte court. */
    public function get(string $key): FieldTypeInterface
    {
        return $this->types[$key] ?? $this->types['text'];
    }

    /** @return array<string, FieldTypeInterface> */
    public function all(): array
    {
        return $this->types;
    }

    /** @return array<string, string> clé => libellé, pour un select */
    public function choices(): array
    {
        return array_map(static fn (FieldTypeInterface $t) => $t->label(), $this->types);
    }

    /**
     * Ce que l'éditeur de champ montre pour chaque type (lu par field_config_controller.js).
     *
     * @return array<string, array{settings: list<string>, bounds: ?string}>
     */
    public function editorConfig(): array
    {
        return array_map(static fn (FieldTypeInterface $t) => ['settings' => $t->settings(), 'bounds' => $t->bounds()], $this->types);
    }
}
