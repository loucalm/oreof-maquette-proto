<?php

declare(strict_types=1);

namespace App\Formulaire\Preview;

use App\Diplome\VersionUsage;
use App\Entity\Formation;
use App\Entity\TypeDiplome;
use App\Entity\TypeDiplomeVersion;
use App\Repository\FormationRepository;

/**
 * Formation qui sert d'hôte à un aperçu : une formation de la version, à défaut du type,
 * à défaut n'importe laquelle ; quand l'aperçu porte sur la fiche parcours, une formation
 * avec parcours est préférée à chaque niveau.
 */
final class PreviewHostFinder
{
    public function __construct(
        private readonly VersionUsage $usage,
        private readonly FormationRepository $formations,
    ) {
    }

    public function find(?TypeDiplomeVersion $version, ?TypeDiplome $type, bool $needsParcours): ?Formation
    {
        $candidates = [
            null !== $version ? $this->usage->formationsUsing($version) : [],
            null !== $type ? $this->usage->formationsOfType($type) : [],
            $this->formations->findBy([], ['name' => 'ASC']),
        ];
        foreach ($candidates as $list) {
            if ([] === $list) {
                continue;
            }
            foreach ($needsParcours ? $list : [] as $formation) {
                if ($formation->isAvecParcours()) {
                    return $formation;
                }
            }

            return $list[0];
        }

        return null;
    }
}
