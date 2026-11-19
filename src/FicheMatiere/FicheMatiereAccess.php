<?php

declare(strict_types=1);

namespace App\FicheMatiere;

use App\Entity\FicheMatiere;
use App\Maquette\Doc\TreeNode;
use App\Repository\FicheMatiereMutualisationRepository;
use App\Repository\FicheMatiereRepository;

/** Détermine quelles fiches matière un EC peut reprendre, selon son parcours. */
final class FicheMatiereAccess
{
    public function __construct(
        private readonly FicheMatiereRepository $fiches,
        private readonly FicheMatiereMutualisationRepository $mutualisations,
    ) {
    }

    /** Premier ancêtre « parcours » du nœud ; null pour le parcours unique d'une formation sans parcours. */
    public function parcoursOf(TreeNode $node): ?TreeNode
    {
        for ($n = $node->getParent(); null !== $n; $n = $n->getParent()) {
            if ($n->isParcours()) {
                return $n;
            }
        }

        return null;
    }

    public function relation(FicheMatiere $fiche, TreeNode $ec): FicheRelation
    {
        if ($fiche->isHorsDiplome()) {
            return FicheRelation::HorsDiplome;
        }

        $formation = $ec->getFormation();
        if (null === $formation || $fiche->getFormation()?->getId() !== $formation->getId()) {
            return $this->isMutualised($fiche, $ec) ? FicheRelation::Mutualisee : FicheRelation::Interdite;
        }

        $parcours = $this->parcoursOf($ec);
        $porteur = $fiche->getParcoursNid();
        if ($porteur === $parcours?->nid) {
            return FicheRelation::Porteur;
        }
        foreach ($this->ancestors($parcours) as $ancestor) {
            if ($ancestor->nid === $porteur) {
                return FicheRelation::Ancetre;
            }
        }

        return $this->isMutualised($fiche, $ec) ? FicheRelation::Mutualisee : FicheRelation::Interdite;
    }

    public function isPorteurOf(FicheMatiere $fiche, TreeNode $ec): bool
    {
        return FicheRelation::Porteur === $this->relation($fiche, $ec);
    }

    /**
     * Fiches utilisables par l'EC : portées par son parcours, par un parcours ancêtre, mutualisées
     * vers son parcours, puis hors diplôme ; sans doublon.
     *
     * @return list<FicheMatiere>
     */
    public function available(TreeNode $ec): array
    {
        $formation = $ec->getFormation();
        $parcours = $this->parcoursOf($ec);
        $groups = [];
        if (null !== $formation) {
            $groups[] = $this->fiches->portedBy($formation, $parcours?->nid);
            foreach ($this->ancestors($parcours) as $ancestor) {
                $groups[] = $this->fiches->portedBy($formation, $ancestor->nid);
            }
            $groups[] = $this->fiches->mutualisedTo($formation, $parcours?->nid);
        }
        $groups[] = $this->fiches->horsDiplome();

        $seen = [];
        $result = [];
        foreach ($groups as $group) {
            foreach ($group as $fiche) {
                if (!isset($seen[$fiche->getId()])) {
                    $seen[$fiche->getId()] = true;
                    $result[] = $fiche;
                }
            }
        }

        return $result;
    }

    /** Parcours ancêtres (portail, puis son propre portail…), du plus proche au plus lointain. @return list<TreeNode> */
    private function ancestors(?TreeNode $parcours): array
    {
        $result = [];
        $seen = [];
        for ($p = $parcours?->getParcoursParent(); null !== $p && !isset($seen[$p->nid]); $p = $p->getParcoursParent()) {
            $seen[$p->nid] = true;
            $result[] = $p;
        }

        return $result;
    }

    private function isMutualised(FicheMatiere $fiche, TreeNode $ec): bool
    {
        $formation = $ec->getFormation();
        if (null === $formation) {
            return false;
        }

        return null !== $this->mutualisations->findOneBy([
            'fiche' => $fiche,
            'formation' => $formation,
            'parcoursNid' => $this->parcoursOf($ec)?->nid,
        ]);
    }
}
