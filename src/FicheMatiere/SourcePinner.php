<?php

declare(strict_types=1);

namespace App\FicheMatiere;

use App\Maquette\Doc\TreeNode;

/**
 * Écrit explicitement `sources[$key] = 'ec'` pour les valeurs déjà remplies d'un EC (spec §7.2 bis) :
 * le changement de contexte ne doit jamais remplacer silencieusement une valeur saisie par une valeur
 * de la fiche. Une source déjà écrite est laissée telle quelle.
 */
final class SourcePinner
{
    /**
     * Le callback est accepté pour que les appelants puissent calculer la source par défaut dans leur
     * contexte, mais l'écriture reste inconditionnelle : la spec exige une source explicite dès qu'une
     * valeur propre est remplie, même si cette source était déjà `ec` par défaut.
     *
     * @param (callable(TreeNode, string): string)|null $defaultSource source par défaut de la valeur
     */
    public function pinFilled(TreeNode $ec, ?callable $defaultSource = null): bool
    {
        $sources = $ec->getAttribute('sources');
        $sources = \is_array($sources) ? $sources : [];
        $pinned = $ec->getAttribute('sourcesFigees');
        $pinned = \is_array($pinned) ? $pinned : [];
        $changed = false;
        foreach (EcValueResolver::KEYS as $key) {
            if (\array_key_exists($key, $sources) || !$this->filled($ec->getAttribute($key))) {
                continue;
            }
            $sources[$key] = EcValueResolver::SOURCE_EC;
            if (!\in_array($key, $pinned, true)) {
                $pinned[] = $key;
            }
            $changed = true;
        }
        if ($changed) {
            $ec->setAttribute('sources', $sources);
            $ec->setAttribute('sourcesFigees', array_values($pinned));
        }

        return $changed;
    }

    private function filled(mixed $value): bool
    {
        return null !== $value && '' !== $value && [] !== $value;
    }
}
