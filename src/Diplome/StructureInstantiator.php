<?php

declare(strict_types=1);

namespace App\Diplome;

use App\Diplome\Spec\Bound;
use App\Diplome\Spec\ImposedElement;
use App\Entity\TypeDiplomeVersion;
use App\Maquette\Doc\MaquetteDoc;
use App\Maquette\Doc\TreeNode;

/**
 * Génère la maquette de départ d'une formation depuis une version de type de
 * diplôme : premier niveau en `duration` exemplaires, chaque niveau suivant au
 * minimum de sa borne `count` (1 à défaut) sous chaque parent, jusqu'au niveau
 * feuille exclu, puis les éléments imposés (verrouillés, marqués `_imposed`).
 *
 * ⚠️ Écrase la structure existante.
 */
final class StructureInstantiator
{
    public function __construct(private readonly ImposedHostLocator $locator)
    {
    }

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
        if ($avecParcours && !$structure->parcoursAllowed) {
            throw new \InvalidArgumentException('Ce type de diplôme n’autorise pas les parcours.');
        }
        foreach ($structure->imposed as $imposed) {
            $this->assertImposedValid($imposed, $chain);
        }

        $doc->formation->setAvecParcours($avecParcours)->setStructure($chain);
        $doc->roots = [];
        $doc->parcours = [];
        $doc->index = [];

        // un conteneur par parcours (multi) ou un seul sans parent (mono)
        $containers = $avecParcours ? [$doc->addNode(null, 'parcours')] : [null];
        $leafIndex = \count($chain) - 1;

        foreach ($containers as $container) {
            /** @var array<string, list<TreeNode>> $byLevel nœuds créés, par niveau, dans l'ordre de l'arbre */
            $byLevel = [];
            $parents = [$container];
            foreach ($chain as $i => $typeKey) {
                if ($i === $leafIndex) {
                    break;
                }
                $count = 0 === $i ? $duration : max(1, $structure->levels[$i]->count?->min ?? 1);
                $created = [];
                foreach ($parents as $parent) {
                    for ($n = 0; $n < $count; ++$n) {
                        $created[] = $doc->addNode($parent?->nid, $typeKey);
                    }
                }
                $byLevel[$typeKey] = $parents = $created;
            }

            foreach ($structure->imposed as $imposed) {
                $hosts = $byLevel[$imposed->parentLevel] ?? [];
                foreach ($this->locator->select($hosts, $imposed->position) as $host) {
                    ImposedHostLocator::addTo($doc, $host, $imposed);
                }
            }
        }
        $doc->reindex();
    }

    /** Une durée est acceptée si elle est d'au moins 1 et dans la borne du type (borne absente = ouverte). */
    public static function acceptsDuration(?Bound $bound, int $n): bool
    {
        return $n >= 1 && (null === $bound || ((null === $bound->min || $n >= $bound->min) && (null === $bound->max || $n <= $bound->max)));
    }

    /** @param list<string> $chain */
    private function assertImposedValid(ImposedElement $imposed, array $chain): void
    {
        $at = array_search($imposed->typeKey, $chain, true);
        if (false === $at || 0 === $at || $chain[$at - 1] !== $imposed->parentLevel) {
            throw new \InvalidArgumentException(sprintf('Élément imposé « %s » : le niveau parent doit être celui situé juste au-dessus de « %s » dans la chaîne.', $imposed->uid, $imposed->typeKey));
        }
    }
}
