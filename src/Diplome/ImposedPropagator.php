<?php

declare(strict_types=1);

namespace App\Diplome;

use App\Diplome\Check\StructureViolationException;
use App\Diplome\Spec\VersionConfig;
use App\Entity\Formation;
use App\Entity\TypeDiplomeVersion;
use App\Maquette\Maquette;

/**
 * Applique à la maquette d'une formation les éléments imposés d'une configuration :
 * dans chaque portée (cf. ImposedHostLocator), ajoute ceux qui en sont absents sous l'hôte
 * désigné par leur position, marqués `_imposed` et verrouillés, et réapplique les verrous
 * de ceux déjà présents (où qu'ils soient dans la portée : jamais de doublon).
 *
 * L'enregistrement passe par `Maquette::save`, donc par le garde de structure :
 * un refus (StructureViolationException) est remonté tel quel, jamais contourné.
 */
final class ImposedPropagator
{
    public function __construct(
        private readonly Maquette $maquette,
        private readonly VersionUsage $usage,
        private readonly ImposedHostLocator $locator,
    ) {
    }

    /** Propage la configuration de la version à chaque formation qui l'applique ; les refus du garde sont recensés, pas contournés. */
    public function propagateVersion(TypeDiplomeVersion $version): PropagationResult
    {
        $config = $version->config();
        $touched = 0;
        $refused = [];
        foreach ($this->usage->formationsUsing($version) as $formation) {
            try {
                $touched += $this->propagate($formation, $config);
            } catch (StructureViolationException $e) {
                $refused[] = ['formation' => $formation, 'message' => $e->getMessage()];
            }
        }

        return new PropagationResult($touched, $refused);
    }

    /**
     * @return int nombre de nœuds touchés (créés, ou dont les verrous ont été remis)
     *
     * @throws StructureViolationException
     */
    public function propagate(Formation $formation, VersionConfig $config): int
    {
        $doc = $this->maquette->open($formation);
        $touched = 0;

        foreach ($config->structure->imposed as $imposed) {
            // portée par portée, sur l'arbre déjà complété : un portail partagé reçoit l'élément une seule fois
            foreach ($this->locator->scopes($doc) as $scope) {
                $present = $this->locator->presentIn($doc, $scope, $imposed);
                if ([] === $present) {
                    foreach ($this->locator->placementHostsIn($doc, $scope, $imposed) as $host) {
                        ImposedHostLocator::addTo($doc, $host, $imposed);
                        ++$touched;
                    }
                    continue;
                }
                foreach ($present as $existing) {
                    if ($existing->locked !== $imposed->locked) {
                        $existing->locked = $imposed->locked;
                        ++$touched;
                    }
                }
            }
        }

        if ($touched > 0) {
            $this->maquette->save($formation, $doc, 'Propagation des éléments imposés');
        }

        return $touched;
    }
}
