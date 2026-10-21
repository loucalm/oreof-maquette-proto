<?php

declare(strict_types=1);

namespace App\Diplome;

use App\Diplome\Check\StructureViolationException;
use App\Diplome\Spec\VersionConfig;
use App\Entity\Formation;
use App\Entity\TypeDiplomeVersion;
use App\Maquette\Doc\MaquetteDoc;
use App\Maquette\Doc\TreeNode;
use App\Maquette\Maquette;

/**
 * Applique à la maquette d'une formation les éléments imposés d'une configuration :
 * dans chaque portée (cf. ImposedHostLocator), ajoute ceux qui manquent, marqués `_imposed` et
 * verrouillés — sous l'hôte désigné par leur position s'ils sont absents de la portée, ou en `all`
 * sous chaque hôte qui en est privé — et réapplique les verrous de ceux déjà présents (jamais de doublon).
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
        $touched = $this->complete($doc, $config);
        foreach ($config->structure->imposed as $imposed) {
            foreach ($doc->allNodes() as $existing) {
                if ($existing->getAttribute('_imposed') === $imposed->uid && $existing->locked !== $imposed->locked) {
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

    /**
     * Ajoute à la maquette (en mémoire, sans l'enregistrer) les éléments imposés qui manquent.
     *
     * Avec `$created` (éditeur : nœud qu'on vient d'ajouter, dupliquer ou raccrocher), seuls les hôtes
     * `all` situés dans ce sous-arbre sont complétés : un écart existant ailleurs (dérogation) reste tel quel.
     *
     * @return int nombre de nœuds créés
     */
    public function complete(MaquetteDoc $doc, VersionConfig $config, ?TreeNode $created = null): int
    {
        $added = 0;
        foreach ($config->structure->imposed as $imposed) {
            if (null !== $created && !$imposed->isEveryHost()) {
                continue;
            }
            // portée par portée, sur l'arbre déjà complété : un portail partagé reçoit l'élément une seule fois
            foreach ($this->locator->scopes($doc) as $scope) {
                foreach ($this->locator->missingHostsIn($doc, $scope, $imposed) as $host) {
                    if (null !== $created && !self::within($host, $created)) {
                        continue;
                    }
                    ImposedHostLocator::addTo($doc, $host, $imposed);
                    ++$added;
                }
            }
        }
        if ($added > 0) {
            $doc->reindex();
        }

        return $added;
    }

    private static function within(TreeNode $node, TreeNode $root): bool
    {
        for ($n = $node; null !== $n; $n = $n->getParent()) {
            if ($n === $root) {
                return true;
            }
        }

        return false;
    }
}
