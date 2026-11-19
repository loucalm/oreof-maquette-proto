<?php

declare(strict_types=1);

namespace App\FicheMatiere;

use App\Entity\FicheMatiere;
use App\Entity\Formation;
use App\Maquette\Doc\TreeNode;
use App\Repository\FicheMatiereMutualisationRepository;
use App\Repository\FicheMatiereRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Cohérence des fiches matière face aux actions sur la maquette (spec §7.2 bis, §9).
 *
 * Un changement de contexte d'un EC n'est jamais silencieux : les valeurs déjà saisies sont
 * figées sur l'EC avant que le résolveur ne puisse les remplacer par une autre source. Le service
 * intervient aussi à la suppression d'une formation, dont les fiches ne doivent disparaître
 * qu'après vérification qu'aucun EC étranger ne les utilise.
 */
final class FicheMatiereLifecycle
{
    public function __construct(
        private readonly FicheMatiereRepository $fiches,
        private readonly FicheMatiereMutualisationRepository $mutualisations,
        private readonly FicheMatiereAccess $access,
        private readonly FicheMatiereMutualiser $mutualiser,
        private readonly FicheMatiereUsage $usage,
        private readonly EcValueResolver $values,
        private readonly SourcePinner $pinner,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** L'EC a changé de parcours : ses valeurs remplies restent les siennes. */
    public function afterMove(TreeNode $ec, ?TreeNode $oldParcours): void
    {
        if (!$ec->getType()->isElementLevel()) {
            return;
        }
        $parcours = $this->access->parcoursOf($ec);
        if (null !== $oldParcours && $oldParcours->getId() === $parcours?->getId()) {
            return;
        }
        $this->pin($ec);
    }

    /**
     * Données d'un EC collées sur un autre : si sa fiche n'est pas utilisable dans le parcours
     * d'accueil, on duplique la fiche pour ce parcours plutôt que de laisser un lien interdit.
     */
    public function afterCopy(TreeNode $copy): void
    {
        if (!$copy->getType()->isElementLevel()) {
            $walk = function (TreeNode $node) use (&$walk): void {
                foreach ($node->getChildren() as $child) {
                    if ($child->getType()->isElementLevel()) {
                        $this->afterCopy($child);
                    } else {
                        $walk($child);
                    }
                }
            };
            $walk($copy);

            return;
        }
        $id = $this->values->ficheId($copy);
        $source = null !== $id ? $this->fiches->find($id) : null;
        if (null !== $source) {
            $allowed = false;
            foreach ($this->access->available($copy) as $available) {
                if ($available->getId() === $id) {
                    $allowed = true;
                    break;
                }
            }
            if (!$allowed) {
                $duplicate = (new FicheMatiere())
                    ->setFormation($copy->getFormation())
                    ->setParcoursNid($this->access->parcoursOf($copy)?->nid)
                    ->setData($source->getData())
                    ->setDefaults($source->getDefaults());
                foreach (FicheMatiere::IMPOSABLE as $key) {
                    if ($source->isImposed($key)) {
                        $duplicate->setImposed($key, true);
                    }
                }
                $this->em->persist($duplicate);
                $this->em->flush();

                $copy->setAttribute('ficheMatiere', $duplicate->getId());
            }
        }

        $this->pin($copy);
    }

    /** Un sous-arbre raccroché depuis une autre formation : ses fiches sont mutualisées vers le parcours d'accueil. */
    public function afterAttach(TreeNode $root, Formation $target): void
    {
        $parcoursNid = $this->access->parcoursOf($root)?->nid;
        $mutualise = function (TreeNode $ec) use ($target, $parcoursNid): void {
            $id = $this->values->ficheId($ec);
            $fiche = null !== $id ? $this->fiches->find($id) : null;
            if (null !== $fiche && !$fiche->isHorsDiplome()
                && !\in_array($this->access->relation($fiche, $ec), [FicheRelation::Porteur, FicheRelation::Ancetre], true)) {
                $this->mutualiser->add($fiche, $target, $parcoursNid);
            }
        };
        $walk = function (TreeNode $node) use (&$walk, $mutualise): void {
            if ($node->getType()->isElementLevel()) {
                $mutualise($node);
            }
            foreach ($node->getChildren() as $child) {
                $walk($child);
            }
        };
        $walk($root);
    }

    /** Le drapeau « enfants identiques » d'un bloc a changé : figer les valeurs de ses EC. */
    public function afterBlocChange(TreeNode $bloc): void
    {
        $walk = function (TreeNode $node) use (&$walk): void {
            foreach ($node->getChildren() as $child) {
                if ($child->getType()->isElementLevel()) {
                    $this->pin($child);
                }
                $walk($child);
            }
        };
        $walk($bloc);
    }

    /**
     * EC d'une autre formation utilisant une fiche portée par celle qu'on veut supprimer.
     *
     * @return list<array{fiche: FicheMatiere, formation: Formation, path: string}>
     */
    public function formationDeletionBlockers(Formation $f): array
    {
        $blockers = [];
        foreach ($this->fiches->ofFormation($f) as $fiche) {
            foreach ($this->usage->usages($fiche) as $usage) {
                if ($usage['formation']->getId() === $f->getId()) {
                    continue;
                }
                $blockers[] = [
                    'fiche' => $fiche,
                    'formation' => $usage['formation'],
                    'path' => $usage['path'],
                ];
            }
        }

        return $blockers;
    }

    /** Supprime les fiches (et leurs mutualisations) avant la formation porteuse (RESTRICT en base). */
    public function deleteFichesOf(Formation $f): void
    {
        foreach ($this->fiches->ofFormation($f) as $fiche) {
            foreach ($this->mutualisations->findBy(['fiche' => $fiche]) as $mutualisation) {
                $this->em->remove($mutualisation);
            }
            $this->em->remove($fiche);
        }
        $this->em->flush();
    }

    private function pin(TreeNode $ec): void
    {
        $this->pinner->pinFilled($ec, fn (TreeNode $node, string $key) => $this->values->defaultSource($node, $key));
    }
}
