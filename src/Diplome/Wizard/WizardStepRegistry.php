<?php

declare(strict_types=1);

namespace App\Diplome\Wizard;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/** Étapes de l'assistant, triées par position (tag `app.diplome_wizard_step`). */
final class WizardStepRegistry
{
    /** @var array<string, WizardStepInterface> */
    private array $steps = [];

    /** @param iterable<WizardStepInterface> $steps */
    public function __construct(#[AutowireIterator(WizardStepInterface::TAG)] iterable $steps)
    {
        $list = [...$steps];
        usort($list, static fn (WizardStepInterface $a, WizardStepInterface $b) => $a->position() <=> $b->position());
        foreach ($list as $step) {
            $this->steps[$step->key()] = $step;
        }
    }

    public function get(string $key): ?WizardStepInterface
    {
        return $this->steps[$key] ?? null;
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->steps);
    }

    public function neighbour(string $key, int $offset): ?string
    {
        $keys = $this->keys();
        $i = array_search($key, $keys, true);

        return false === $i ? null : ($keys[$i + $offset] ?? null);
    }
}
