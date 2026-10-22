<?php

declare(strict_types=1);

namespace App\Diplome;

use App\Diplome\Spec\DurationSpec;
use App\Repository\NodeTypeRepository;
use Symfony\Contracts\Service\ResetInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Durée en clair, dans l'unité du niveau qui la compte : « 6 semestres », « 3 à 4 années », « au moins 2 années ». */
final class DurationLabeler implements ResetInterface
{
    /** @var array<string, \App\Entity\NodeType>|null métamodèle lu une seule fois (une liste affiche une durée par ligne) */
    private ?array $indexed = null;

    public function __construct(
        private readonly NodeTypeRepository $nodeTypes,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /** Null si la durée n'est pas bornée. */
    public function describe(?DurationSpec $duration): ?string
    {
        $bound = $duration?->bound;
        if (null === $duration || null === $bound || (null === $bound->min && null === $bound->max)) {
            return null;
        }
        $key = match (true) {
            $bound->isExact() => 'exact',
            null !== $bound->min && null !== $bound->max => 'range',
            null !== $bound->min => 'min',
            default => 'max',
        };

        return $this->translator->trans('diplome.index.duration_'.$key, [
            '%n%' => $bound->min, '%min%' => $bound->min, '%max%' => $bound->max,
            '%unit%' => $this->unit($duration->levelKey, $bound->max ?? $bound->min ?? 0),
        ], 'diplome');
    }

    /** Libellé du niveau, en minuscules, au pluriel si $count > 1. */
    public function unit(string $levelKey, int $count = 2): string
    {
        $this->indexed ??= $this->nodeTypes->findAllIndexed();
        $label = mb_strtolower(($this->indexed[$levelKey] ?? null)?->getLabel() ?? $levelKey);

        return $count > 1 ? $label.'s' : $label;
    }

    public function reset(): void
    {
        $this->indexed = null;
    }
}
