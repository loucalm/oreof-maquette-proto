<?php

declare(strict_types=1);

namespace App\Diplome;

use App\Diplome\Spec\VersionConfig;
use App\Entity\Formation;
use App\Maquette\Maquette;

/**
 * Applique à la maquette d'une formation les éléments imposés d'une configuration :
 * ajoute ceux qui manquent sous leurs hôtes (cf. ImposedHostLocator), marqués `_imposed`
 * et verrouillés, et réapplique les verrous de ceux déjà en place.
 *
 * L'enregistrement passe par `Maquette::save`, donc par le garde de structure :
 * un refus (StructureViolationException) est remonté tel quel, jamais contourné.
 */
final class ImposedPropagator
{
    private readonly ImposedHostLocator $locator;

    public function __construct(private readonly Maquette $maquette, ?ImposedHostLocator $locator = null)
    {
        $this->locator = $locator ?? new ImposedHostLocator();
    }

    /**
     * @return int nombre de nœuds touchés (créés, ou dont les verrous ont été remis)
     *
     * @throws \App\Diplome\Check\StructureViolationException
     */
    public function propagate(Formation $formation, VersionConfig $config): int
    {
        $doc = $this->maquette->open($formation);
        $touched = 0;

        foreach ($config->structure->imposed as $imposed) {
            foreach ($this->locator->hostsIn($doc, $imposed) as $host) {
                $existing = null;
                foreach ($host->getChildren() as $child) {
                    if ($child->getAttribute('_imposed') === $imposed->uid) {
                        $existing = $child;
                        break;
                    }
                }
                if (null === $existing) {
                    $node = $doc->addNode($host->nid, $imposed->typeKey, $imposed->label);
                    $node->locked = $imposed->locked;
                    $node->setAttribute('_imposed', $imposed->uid);
                    $node->setAttribute('natureElement', $imposed->nature);
                    ++$touched;
                } elseif ($existing->locked !== $imposed->locked) {
                    $existing->locked = $imposed->locked;
                    ++$touched;
                }
            }
        }

        if ($touched > 0) {
            $this->maquette->save($formation, $doc, 'Propagation des éléments imposés');
        }

        return $touched;
    }
}
