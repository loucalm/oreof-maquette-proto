<?php

declare(strict_types=1);

namespace App\Formulaire\Builtin;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Écrans intégrés d'une fiche, indexés par clé (tag `app.builtin_screen`). Les écrans ne
 * sont instanciés qu'au premier usage : certains dépendent de services (Completion) qui
 * dépendent eux-mêmes du canevas.
 */
final class BuiltinScreenRegistry
{
    /** @var array<string, array<string, BuiltinScreenInterface>>|null */
    private ?array $screens = null;

    /** @param iterable<BuiltinScreenInterface> $iterable */
    public function __construct(#[AutowireIterator(BuiltinScreenInterface::TAG)] private readonly iterable $iterable)
    {
    }

    public function get(string $fiche, string $key): ?BuiltinScreenInterface
    {
        return $this->screens()[$fiche][$key] ?? null;
    }

    /** @return list<BuiltinScreenInterface> écrans de la fiche, triés par position par défaut */
    public function forFiche(string $fiche): array
    {
        $list = array_values($this->screens()[$fiche] ?? []);
        usort($list, static fn (BuiltinScreenInterface $a, BuiltinScreenInterface $b) => [$a->defaultPosition(), $a->key()] <=> [$b->defaultPosition(), $b->key()]);

        return $list;
    }

    /** @return array<string, array<string, BuiltinScreenInterface>> */
    private function screens(): array
    {
        if (null === $this->screens) {
            $this->screens = [];
            foreach ($this->iterable as $screen) {
                $this->screens[$screen->fiche()][$screen->key()] = $screen;
            }
        }

        return $this->screens;
    }
}
