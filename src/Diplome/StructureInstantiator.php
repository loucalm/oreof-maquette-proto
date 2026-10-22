<?php

declare(strict_types=1);

namespace App\Diplome;

use App\Diplome\Spec\Bound;
use App\Diplome\Spec\DurationMath;
use App\Entity\TypeDiplomeVersion;
use App\Maquette\Doc\MaquetteDoc;

/**
 * Génère la maquette de départ d'une formation depuis une version de type de
 * diplôme : premier niveau en autant d'exemplaires que la durée l'impose (`duration` est
 * exprimée en nœuds du niveau de durée, cf. DurationMath), chaque niveau suivant au
 * minimum de sa borne `count` (1 à défaut) sous chaque parent, jusqu'au niveau
 * feuille exclu.
 *
 * ⚠️ Écrase la structure existante.
 */
final class StructureInstantiator
{
    public function instantiate(MaquetteDoc $doc, TypeDiplomeVersion $version, int $duration, bool $avecParcours): void
    {
        $structure = $version->config()->structure;
        $chain = array_map(static fn ($level) => $level->typeKey, $structure->levels);
        if ([] === $chain) {
            throw new \InvalidArgumentException('La version ne définit aucun niveau de structure.');
        }
        if (!self::acceptsDuration($structure->duration?->bound, $duration)) {
            throw new \InvalidArgumentException(sprintf('Durée %d hors des bornes du type de diplôme.', $duration));
        }
        $firstLevelCount = DurationMath::firstLevelCount($structure, $duration);
        if ($avecParcours && !$structure->parcoursAllowed) {
            throw new \InvalidArgumentException('Ce type de diplôme n’autorise pas les parcours.');
        }

        $doc->formation->setAvecParcours($avecParcours)->setStructure($chain);
        $doc->roots = [];
        $doc->parcours = [];
        $doc->index = [];

        // un conteneur par parcours (multi) ou un seul sans parent (mono)
        $containers = $avecParcours ? [$doc->addNode(null, 'parcours')] : [null];
        $leafIndex = \count($chain) - 1;

        foreach ($containers as $container) {
            $parents = [$container];
            foreach ($chain as $i => $typeKey) {
                if ($i === $leafIndex) {
                    break;
                }
                $count = 0 === $i ? $firstLevelCount : max(1, $structure->levels[$i]->count?->min ?? 1);
                $created = [];
                foreach ($parents as $parent) {
                    for ($n = 0; $n < $count; ++$n) {
                        $created[] = $doc->addNode($parent?->nid, $typeKey);
                    }
                }
                $parents = $created;
            }
        }
        $doc->reindex();
    }

    /** Une durée est acceptée si elle est d'au moins 1 et dans la borne du type (borne absente = ouverte). */
    public static function acceptsDuration(?Bound $bound, int $n): bool
    {
        return $n >= 1 && (null === $bound || ((null === $bound->min || $n >= $bound->min) && (null === $bound->max || $n <= $bound->max)));
    }
}
