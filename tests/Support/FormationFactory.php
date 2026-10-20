<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Diplome\FormationCreator;
use App\Diplome\TypeDiplomeResolver;
use App\Entity\Formation;
use App\Maquette\Doc\MaquetteDoc;
use App\Maquette\Maquette;
use App\Repository\TypeDiplomeRepository;

/** Crée une formation persistée d'après un type de diplôme des fixtures (L, LP, M, M2E, BUT), comme à l'écran. */
final class FormationFactory
{
    public function __construct(
        private readonly TypeDiplomeRepository $types,
        private readonly FormationCreator $creator,
        private readonly Maquette $maquette,
    ) {
    }

    public static function create(\Psr\Container\ContainerInterface $container): self
    {
        return new self(
            $container->get(TypeDiplomeRepository::class),
            $container->get(FormationCreator::class),
            $container->get(Maquette::class),
        );
    }

    public function fromType(string $typeKey, int $duration, string $name = 'Formation de test', bool $avecParcours = false, bool $withLeaves = false): Formation
    {
        $type = $this->types->findOneByKey($typeKey) ?? throw new \LogicException("Type de diplôme « $typeKey » absent des fixtures.");

        $formation = $this->creator->create($name, $type, TypeDiplomeResolver::DEFAULT_YEAR, $duration, $avecParcours);

        return $withLeaves ? $this->withLeaves($formation) : $formation;
    }

    /**
     * Ajoute un élément feuille sous chaque nœud du niveau juste au-dessus (la maquette de départ d'un
     * type s'arrête avant le niveau feuille) : de quoi tester ce qui porte sur les EC (`withLeaves: true`).
     */
    private function withLeaves(Formation $formation, string $label = 'EC à compléter'): Formation
    {
        $maquette = $this->maquette;
        $doc = $maquette->open($formation);
        $chain = $formation->getStructure();
        $leaf = $chain[\count($chain) - 1];
        $host = $chain[\count($chain) - 2];
        foreach ($doc->allNodes() as $node) {
            if ($node->typeKey === $host) {
                $doc->addNode($node->nid, $leaf, $label);
            }
        }
        $maquette->save($formation, $doc, 'Éléments feuilles');

        return $formation;
    }

    /** Premier nœud du type donné (ordre de l'arbre). */
    public function first(MaquetteDoc $doc, string $typeKey): \App\Maquette\Doc\TreeNode
    {
        foreach ($doc->allNodes() as $node) {
            if ($node->typeKey === $typeKey) {
                return $node;
            }
        }

        throw new \LogicException("Aucun nœud de type « $typeKey ».");
    }
}
