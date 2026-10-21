<?php

declare(strict_types=1);

namespace App\Formulaire\Fiche;

use App\Entity\Formation;
use App\Maquette\Doc\TreeNode;
use App\Maquette\Maquette;

/** Fiches d'une formation, sur sa maquette ouverte (la même instance pour toute la requête). */
final class FicheFactory
{
    public function __construct(private readonly Maquette $maquette)
    {
    }

    public function formation(Formation $formation): FormationFiche
    {
        return new FormationFiche($formation, $this->maquette->open($formation));
    }

    /** Null si `$nid` n'est pas un parcours de la formation. */
    public function parcours(Formation $formation, string $nid): ?ParcoursFiche
    {
        $doc = $this->maquette->open($formation);
        $node = $doc->node($nid);

        return null !== $node && $node->isParcours() ? new ParcoursFiche($formation, $doc, $node) : null;
    }

    public function forNode(TreeNode $parcours): ParcoursFiche
    {
        $formation = $parcours->getFormation() ?? throw new \LogicException('Parcours sans formation.');

        return new ParcoursFiche($formation, $this->maquette->open($formation), $parcours);
    }

    public function mono(Formation $formation): MonoParcoursFiche
    {
        return new MonoParcoursFiche($formation, $this->maquette->open($formation));
    }
}
