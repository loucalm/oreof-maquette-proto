<?php

declare(strict_types=1);

namespace App\Diplome\Spec;

/**
 * Arithmétique de la durée : elle se compte sur un niveau temporel de la chaîne (année, semestre…),
 * pas forcément le premier. Le « facteur » est le nombre de nœuds du niveau de durée par nœud du
 * premier niveau (ex. 2 semestres par année) ; il permet de retrouver le nombre de nœuds du premier niveau.
 */
final class DurationMath
{
    /** Produit des nombres minimaux par parent des niveaux situés après le premier, jusqu'au niveau de durée inclus (1 sans durée ou si c'est le premier niveau). */
    public static function factor(StructureSpec $structure): int
    {
        $at = self::durationIndex($structure);
        $factor = 1;
        for ($i = 1; $i <= ($at ?? 0); ++$i) {
            $factor *= max(1, $structure->levels[$i]->count?->min ?? 1);
        }

        return $factor;
    }

    /**
     * Nombre de nœuds du premier niveau pour une durée exprimée en nœuds du niveau de durée.
     *
     * @throws \InvalidArgumentException si la durée n'est pas un multiple du facteur
     */
    public static function firstLevelCount(StructureSpec $structure, int $duration): int
    {
        $factor = self::factor($structure);
        if (0 !== $duration % $factor) {
            throw new \InvalidArgumentException(\sprintf('La durée (%d) n\'est pas un multiple de %d.', $duration, $factor));
        }

        return intdiv($duration, $factor);
    }

    /**
     * Clé de traduction (domaine `diplome`) du premier défaut de la durée, null si elle est cohérente.
     *
     * @param list<string> $temporalKeys clés des types de nœud temporels
     */
    public static function problem(StructureSpec $structure, array $temporalKeys): ?string
    {
        $duration = $structure->duration;
        if (null === $duration) {
            return null;
        }
        $at = self::durationIndex($structure);
        if (null === $at) {
            return 'diplome.wizard.structure.error.duration_level_not_in_chain';
        }
        if (!\in_array($duration->levelKey, $temporalKeys, true)) {
            return 'diplome.wizard.structure.error.duration_level_not_temporal';
        }
        for ($i = 1; $i <= $at; ++$i) {
            if (!($structure->levels[$i]->count?->isExact() ?? false)) {
                return 'diplome.wizard.structure.error.duration_intermediate_not_exact';
            }
        }
        $factor = self::factor($structure);
        $min = $duration->bound->min;
        $max = $duration->bound->max;
        if ($factor > 1 && null !== $min && null !== $max && intdiv($max, $factor) * $factor < $min) {
            return 'diplome.wizard.structure.error.duration_not_divisible';
        }

        return null;
    }

    private static function durationIndex(StructureSpec $structure): ?int
    {
        if (null === $structure->duration) {
            return null;
        }
        foreach ($structure->levels as $i => $level) {
            if ($level->typeKey === $structure->duration->levelKey) {
                return $i;
            }
        }

        return null;
    }
}
