<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\FicheMatiere;
use App\Entity\FicheMatiereMutualisation;
use App\Entity\Formation;
use App\Maquette\Doc\MaquetteDoc;
use App\Maquette\Maquette;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * Fiches matière de démonstration (spec §11) : une fiche par EC des formations de démo,
 * reprise des quatre libellés de l'ancien référentiel, mutualisation entre deux parcours,
 * fiche hors diplôme, valeurs imposées et source « porteur ».
 *
 * Les EC sont créés par FormationFixtures ; ici on ne fait que rattacher leur id de fiche
 * (l'ancien attribut textuel n'est plus utilisé) et déclarer les mutualisations.
 */
final class FicheMatiereFixtures extends Fixture implements DependentFixtureInterface
{
    public function __construct(
        private readonly Maquette $maquette,
    ) {
    }

    public function getDependencies(): array
    {
        return [FormationFixtures::class];
    }

    public function load(ObjectManager $manager): void
    {
        $licence = $this->formation($manager, 'Licence Informatique (démo)');
        $multi = $this->formation($manager, 'Licence Sciences & Technologies (démo avec parcours)');
        $but = $this->formation($manager, 'BUT Informatique (démo)');

        $docMulti = $this->maquette->open($multi);
        $infoNid = $this->parcoursNid($docMulti, 'Parcours Informatique');
        $mathsNid = $this->parcoursNid($docMulti, 'Parcours Mathématiques');

        // Les quatre libellés de l'ancien référentiel fiches_matiere sont conservés tels quels.
        $algo = $this->fiche($manager, 'Fiche — Algorithmique', $licence, null, [
            'hours' => ['pres' => ['cm' => 12, 'td' => 18], 'te' => 20],
            'ects' => 5,
            'mccc' => ['type' => 'CCI'],
            'competencies' => ['1A', '1B'],
        ]);
        $prog = $this->fiche($manager, 'Fiche — Programmation', $licence, null, [
            'hours' => ['pres' => ['tp' => 24], 'dist' => ['tp' => 6]],
            'ects' => 5,
            'mccc' => ['type' => 'CC_CT'],
            'competencies' => ['1A', '1B'],
        ], [], ['mccc' => true]);
        $maths = $this->fiche($manager, 'Fiche — Mathématiques', $licence, null, [
            'hours' => ['pres' => ['cm' => 20]],
            'ects' => 5,
            'mccc' => ['type' => 'CT'],
            'competencies' => ['3A', '3B'],
        ]);
        // Volontairement incomplète (langue des supports absente) : alimente la page « Vérifier ».
        $algebra = $this->fiche($manager, 'Fiche — Algèbre', $licence, null, [
            'hours' => ['pres' => ['td' => 20]],
            'ects' => 5,
            'mccc' => ['type' => 'CT'],
        ], ['languesSupport' => null]);

        $anglais = $this->fiche($manager, 'Anglais', null, null, [
            'hours' => ['pres' => ['td' => 24]],
            'ects' => 10,
            'mccc' => ['type' => 'CCI'],
        ], [], [], true);

        $bdd = $this->fiche($manager, 'Fiche — Bases de données', $but, null, [
            'hours' => ['pres' => ['cm' => 12, 'tp' => 18]],
            'ects' => 10,
            'mccc' => ['type' => 'CC_CT'],
        ]);
        $sae = $this->fiche($manager, 'Fiche — SAÉ 1.01', $but, null, [
            'hours' => ['pres' => ['tp' => 24]],
            'ects' => 10,
            'mccc' => ['type' => 'Rapport'],
        ]);
        $stages = [];
        foreach ([2, 4, 6] as $n) {
            $stages[$n] = $this->fiche($manager, sprintf('Fiche — Stage S%d', $n), $but, null, [
                'hours' => ['none' => true],
                'ects' => 6,
                'mccc' => ['type' => 'Rapport'],
            ]);
        }

        // Fiche portée par le parcours Informatique, utilisée aussi en Mathématiques : l'EC du second
        // parcours reprend les MCCC du porteur. Les MCCC imposées sont portées par une autre fiche
        // de démonstration, pour que la source « porteur » reste effective (spec §7.1).
        $shared = $this->fiche($manager, 'Fiche — Mathématiques pour l’informatique', $multi, $infoNid, [
            'hours' => ['pres' => ['cm' => 18, 'td' => 12]],
            'ects' => 5,
            'mccc' => ['type' => 'CCI', 'evaluations' => [
                ['type' => 'cc', 'weight' => 40.0],
                ['type' => 'ct', 'weight' => 60.0],
            ]],
        ]);
        $manager->flush();

        $manager->persist(new FicheMatiereMutualisation($shared, $multi, $mathsNid));
        $manager->flush();

        $this->link($licence, [
            'Algorithmique' => [$algo, []],
            'Langage C' => [$prog, []],
            'Analyse' => [$maths, []],
            'Algèbre' => [$algebra, []],
            'Anglais' => [$anglais, []],
        ]);
        $this->link($multi, [
            'Mathématiques pour l’informatique' => [$shared, []],
            'Algèbre linéaire' => [$shared, ['mccc' => 'porteur']],
        ]);
        $this->link($but, [
            'Ressource R1.01 — Initiation au développement' => [$bdd, []],
            'SAÉ 1.01 — Implémentation d’un besoin client' => [$sae, []],
            'Stage S2' => [$stages[2], []],
            'Stage S4' => [$stages[4], []],
            'Stage S6' => [$stages[6], []],
        ]);
    }

    private function formation(ObjectManager $manager, string $name): Formation
    {
        return $manager->getRepository(Formation::class)->findOneBy(['name' => $name])
            ?? throw new \LogicException(sprintf('Formation de démo « %s » introuvable.', $name));
    }

    private function parcoursNid(MaquetteDoc $doc, string $label): string
    {
        foreach ($doc->parcoursNodes() as $parcours) {
            if ($parcours->getLabel() === $label) {
                return $parcours->nid;
            }
        }

        throw new \LogicException(sprintf('Parcours de démo « %s » introuvable.', $label));
    }

    /** @param array<string, mixed> $defaults @param array<string, mixed> $data @param array<string, bool> $imposed */
    private function fiche(
        ObjectManager $manager,
        string $libelle,
        ?Formation $formation,
        ?string $parcoursNid,
        array $defaults,
        array $data = [],
        array $imposed = [],
        bool $horsDiplome = false,
    ): FicheMatiere {
        $fiche = (new FicheMatiere())
            ->setFormation($formation)
            ->setParcoursNid($horsDiplome ? null : $parcoursNid)
            ->setHorsDiplome($horsDiplome)
            ->setData($data + [
                'libelle' => $libelle,
                'languesCours' => ['Français'],
                'languesSupport' => ['Français'],
                'description' => sprintf('Fiche de démonstration « %s ».', $libelle),
                'objectifsEnseignement' => 'Fiche de démonstration — à compléter dans l’application réelle.',
            ])
            ->setDefaults($defaults);
        foreach ($imposed as $key => $on) {
            $fiche->setImposed($key, $on);
        }
        $manager->persist($fiche);

        return $fiche;
    }

    /**
     * @param array<string, array{0: FicheMatiere, 1: array<string, string>}> $map libellé d'EC => [fiche, sources]
     */
    private function link(Formation $formation, array $map): void
    {
        $doc = $this->maquette->open($formation);
        $seen = [];
        foreach ($doc->allNodes() as $node) {
            if (!$node->getType()->isElementLevel() || !isset($map[$node->getLabel()])) {
                continue;
            }
            [$fiche, $sources] = $map[$node->getLabel()];
            $node->setAttribute('ficheMatiere', $fiche->getId());
            if ([] !== $sources) {
                $node->setAttribute('sources', $sources);
            }
            $seen[$node->getLabel()] = true;
        }
        foreach (array_keys($map) as $label) {
            if (!isset($seen[$label])) {
                throw new \LogicException(sprintf('EC « %s » introuvable dans « %s ».', $label, $formation->getName()));
            }
        }
        $this->maquette->save($formation, $doc, 'Fiches matière de démonstration');
    }
}
