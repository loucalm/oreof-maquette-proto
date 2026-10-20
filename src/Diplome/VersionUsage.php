<?php

declare(strict_types=1);

namespace App\Diplome;

use App\Entity\Formation;
use App\Entity\TypeDiplomeVersion;
use App\Repository\FormationRepository;

/** Formations qui appliquent une version donnée (version résolue = celle-ci : son année, ou la plus récente antérieure). */
final class VersionUsage
{
    public function __construct(
        private readonly FormationRepository $formations,
        private readonly TypeDiplomeResolver $resolver,
    ) {
    }

    /** @return list<Formation> */
    public function formationsUsing(TypeDiplomeVersion $version): array
    {
        $using = [];
        foreach ($this->formations->findBy(['typeDiplome' => $version->getTypeDiplome()], ['name' => 'ASC']) as $formation) {
            if ($this->resolver->versionFor($formation)?->getId() === $version->getId()) {
                $using[] = $formation;
            }
        }

        return $using;
    }
}
