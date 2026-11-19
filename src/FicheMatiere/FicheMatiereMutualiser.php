<?php

declare(strict_types=1);

namespace App\FicheMatiere;

use App\Entity\FicheMatiere;
use App\Entity\FicheMatiereMutualisation;
use App\Entity\Formation;
use App\Maquette\Doc\TreeNode;
use App\Maquette\Maquette;
use App\Repository\FicheMatiereMutualisationRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Mutualisation d'une fiche (spec §8.3) et changement de parcours porteur (§7.2 bis, §8.3).
 * Les retraits gardent le lien des EC : seule la complétude signale alors la fiche non autorisée.
 */
final class FicheMatiereMutualiser
{
    public function __construct(
        private readonly FicheMatiereMutualisationRepository $mutualisations,
        private readonly EntityManagerInterface $em,
        private readonly Maquette $maquette,
        private readonly FicheMatiereUsage $usage,
        private readonly FicheMatiereAccess $access,
        private readonly SourcePinner $pinner,
    ) {
    }

    /** La contrainte d'unicité ne joue pas sur un `parcoursNid` NULL : l'existence est vérifiée ici. */
    public function add(FicheMatiere $fiche, Formation $formation, ?string $parcoursNid): void
    {
        if (null !== $this->find($fiche, $formation, $parcoursNid)) {
            return;
        }
        $this->em->persist(new FicheMatiereMutualisation($fiche, $formation, $parcoursNid));
        $this->em->flush();
    }

    public function remove(FicheMatiereMutualisation $mutualisation): void
    {
        $this->em->remove($mutualisation);
        $this->em->flush();
    }

    public function clear(FicheMatiere $fiche): void
    {
        foreach ($this->mutualisations->findBy(['fiche' => $fiche]) as $mutualisation) {
            $this->em->remove($mutualisation);
        }
        $this->em->flush();
    }

    /** EC du parcours retiré (et de ses descendants) qui utilisent encore la fiche. */
    public function impactsOfRemoval(FicheMatiereMutualisation $mutualisation): array
    {
        $out = [];
        foreach ($this->usage->usages($mutualisation->getFiche()) as $usage) {
            if (!$this->concerns($usage['ec'], $mutualisation->getFormation(), $mutualisation->getParcoursNid())) {
                continue;
            }
            $out[] = [
                'formation' => $usage['formation'],
                'ec' => $usage['ec'],
                'path' => $usage['path'],
            ];
        }

        return $out;
    }

    /**
     * Avant confirmation, rien n'est modifié : les impacts sont seulement renvoyés. Après confirmation,
     * les valeurs propres des EC sont écrites comme sources, puis les compétences étrangères au nouveau
     * porteur sont retirées de la fiche.
     */
    public function changePorteur(FicheMatiere $fiche, Formation $formation, ?string $parcoursNid, bool $confirm): SaveResult
    {
        $dropped = $this->foreignCompetencies($fiche, $formation, $parcoursNid);
        $impacts = $this->porteurImpacts($fiche, $formation, $parcoursNid, $dropped);
        // sans impact, rien à confirmer : le changement s'applique directement
        if (!$confirm && [] !== $impacts) {
            return new SaveResult(false, $impacts);
        }

        $docs = [];
        foreach ($this->usage->usages($fiche) as $usage) {
            if ($this->pinner->pinFilled($usage['ec'])) {
                $docs[$usage['formation']->getId()] = [$usage['formation'], $usage['ec']->doc ?? $this->maquette->open($usage['formation'])];
            }
        }
        foreach ($docs as [$docFormation, $doc]) {
            $this->maquette->save($docFormation, $doc);
        }

        if ([] !== $dropped) {
            $competencies = array_values(array_filter(
                \is_array($fiche->getDefault('competencies')) ? $fiche->getDefault('competencies') : [],
                static fn (mixed $value): bool => !\in_array((string) $value, array_map('strval', $dropped), true),
            ));
            $fiche->setDefault('competencies', $competencies);
        }
        $fiche->setFormation($formation)->setParcoursNid($parcoursNid)->setHorsDiplome(false);
        $this->em->flush();

        return new SaveResult(true, $impacts);
    }

    private function find(FicheMatiere $fiche, Formation $formation, ?string $parcoursNid): ?FicheMatiereMutualisation
    {
        return $this->mutualisations->findOneBy([
            'fiche' => $fiche,
            'formation' => $formation,
            'parcoursNid' => $parcoursNid,
        ]);
    }

    /** Le parcours d'un EC est concerné s'il est le parcours retiré ou l'un de ses descendants. */
    private function concerns(TreeNode $ec, Formation $formation, ?string $parcoursNid): bool
    {
        if ($ec->getFormation()?->getId() !== $formation->getId()) {
            return false;
        }
        $parcours = $this->access->parcoursOf($ec);
        if ($parcoursNid === $parcours?->nid) {
            return true;
        }
        for ($parent = $parcours?->getParcoursParent(); null !== $parent; $parent = $parent->getParcoursParent()) {
            if ($parcoursNid === $parent->nid) {
                return true;
            }
        }

        return false;
    }

    /** @return list<array{formation: string, path: string, key: string}> */
    private function porteurImpacts(FicheMatiere $fiche, Formation $formation, ?string $parcoursNid, array $dropped): array
    {
        $out = [];
        foreach ($this->usage->usages($fiche) as $usage) {
            $wasPorteur = FicheRelation::Porteur === $this->access->relation($fiche, $usage['ec']);
            $willBePorteur = $formation->getId() === $usage['formation']->getId()
                && $parcoursNid === $this->access->parcoursOf($usage['ec'])?->nid;
            if ($wasPorteur === $willBePorteur) {
                continue;
            }
            $out[] = [
                'formation' => $usage['formation']->getName(),
                'path' => $usage['path'],
                'key' => $willBePorteur ? 'porteur' : 'utilisateur',
            ];
        }
        foreach ($dropped as $competence) {
            $out[] = ['formation' => $formation->getName(), 'path' => (string) $competence, 'key' => 'competencies'];
        }

        return $out;
    }

    /** Compétences de la fiche qui ne valent plus dans le BCC du nouveau porteur. @return list<int|string> */
    private function foreignCompetencies(FicheMatiere $fiche, Formation $formation, ?string $parcoursNid): array
    {
        $competencies = $fiche->getDefault('competencies');
        if (!\is_array($competencies)) {
            return [];
        }
        $allowed = array_map('strval', $this->allowedCompetencies($formation, $parcoursNid));

        return array_values(array_filter(
            $competencies,
            static fn (mixed $value): bool => !\in_array((string) $value, $allowed, true),
        ));
    }

    /** Identifiants et libellés des compétences proposables au nouveau porteur. @return list<string> */
    private function allowedCompetencies(Formation $formation, ?string $parcoursNid): array
    {
        if ($formation->hasBccReferentiel()) {
            $out = [];
            foreach ($formation->getBccReferentiel() as $competence) {
                foreach ($competence['niveaux'] ?? [] as $niveau) {
                    foreach ($niveau['acs'] ?? [] as $ac) {
                        if (isset($ac['code'])) {
                            $out[] = (string) $ac['code'];
                        }
                    }
                }
            }

            return $out;
        }

        $doc = $this->maquette->open($formation);
        $blocs = null === $parcoursNid ? $doc->competenceBlocs() : ($doc->node($parcoursNid)?->getBccBlocs() ?? []);
        $out = [];
        $walk = function (TreeNode $node) use (&$walk, &$out): void {
            if ($node->isCompetenceNode()) {
                $out[] = $node->nid;
                $out[] = $node->getLabel();
            }
            foreach ($node->getChildren() as $child) {
                $walk($child);
            }
        };
        foreach ($blocs as $bloc) {
            $walk($bloc);
        }

        return $out;
    }
}
