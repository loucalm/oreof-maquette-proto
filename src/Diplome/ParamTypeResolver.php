<?php

declare(strict_types=1);

namespace App\Diplome;

use App\Entity\Formation;
use App\Entity\NodeType;
use App\Repository\NodeTypeRepository;

/**
 * Type de nœud « paramètre » (famille `parameter`) qui pilote une section de formulaire
 * (organisation, présentation) d'une formation ou d'un parcours : celui que la version du
 * type de diplôme désigne à l'emplacement `formation.<section>` / `parcours.<section>`,
 * à défaut la convention `param_<section>_formation` / `param_<section>_parcours`.
 */
final class ParamTypeResolver
{
    public const SCOPE_FORMATION = 'formation';
    public const SCOPE_PARCOURS = 'parcours';

    public function __construct(
        private readonly NodeTypeRepository $types,
        private readonly TypeDiplomeResolver $resolver,
    ) {
    }

    public function typeKey(?Formation $formation, string $scope, string $section): string
    {
        $configured = null !== $formation ? ($this->resolver->configFor($formation)?->forms[$scope.'.'.$section] ?? null) : null;

        return null !== $configured && '' !== $configured ? $configured : 'param_'.$section.'_'.$scope;
    }

    public function typeFor(?Formation $formation, string $scope, string $section): ?NodeType
    {
        $type = $this->types->findOneByKey($this->typeKey($formation, $scope, $section));

        // une version qui désigne un type disparu retombe sur la convention plutôt que de vider la section
        return $type ?? $this->types->findOneByKey('param_'.$section.'_'.$scope);
    }
}
