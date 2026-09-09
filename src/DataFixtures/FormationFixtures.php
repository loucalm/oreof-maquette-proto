<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Formation;
use App\Entity\Node;
use App\Entity\NodeType;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * Une formation de démo avec un petit arbre partiellement rempli, pour voir
 * les statuts (vide / incomplet / ok) et les agrégats dès l'ouverture.
 */
final class FormationFixtures extends Fixture implements DependentFixtureInterface
{
    public function getDependencies(): array
    {
        return [NodeTypeFixtures::class];
    }

    public function load(ObjectManager $manager): void
    {
        /** @var array<string, NodeType> $t */
        $t = [];
        foreach (['annee', 'semestre', 'ue', 'ec'] as $k) {
            $t[$k] = $this->getReference(NodeTypeFixtures::REF_PREFIX.$k, NodeType::class);
        }

        $formation = (new Formation('Licence Informatique (démo)'))
            ->setDiplome('Licence')
            ->setDomaine('Sciences, technologies, santé')
            ->setComposante('UFR Sciences Exactes et Naturelles')
            ->setMultiParcours(false)
            ->setStructure(['annee', 'semestre', 'ue', 'ec'])
            ->setEctsTotal(180)
            ->setParametre('organisation', [
                'contacts' => "Secrétariat pédagogique — licence.info@univ-demo.fr\n+33 (0)1 23 45 67 89",
                'mention' => 'Informatique',
                'niveauEntree' => 'Baccalauréat',
                'niveauSortie' => 'Bac+3',
                'rncp' => 'oui',
                'codeRncp' => '24514',
                'codeApogee' => 'L-INFO-01',
                'respMention' => 'A. Martin',
                'coRespMention' => 'C. Bernard',
            ])
            ->setParametre('presentation', [
                'objectif' => "Former des informaticiens polyvalents maîtrisant les fondements de la programmation, des algorithmes, des systèmes et des bases de données.",
                'resultats' => "À l'issue de la formation, l'étudiant conçoit et développe une application, modélise des données et déploie un service.",
                'contenu' => "Programmation, algorithmique, mathématiques pour l'informatique, systèmes et réseaux, bases de données, développement web, projet tutoré.",
                'rythme' => 'Temps plein',
            ])
            ->setParametre('structure', [
                'regimes' => ['FI', 'FC'],
                'langue' => 'Français',
                'modalitesAlternance' => 'Sans objet (formation initiale à temps plein).',
                'poursuiteEtudes' => "Master informatique, master MIAGE, écoles d'ingénieurs sur titre.",
                'debouches' => "Développeur, administrateur systèmes et bases de données, assistant chef de projet.",
                'codesRome' => 'M1805 — Études et développement informatique',
            ]);
        $manager->persist($formation);

        $pos = 0;
        $mk = function (NodeType $type, string $label, ?Node $parent, array $attrs = []) use ($manager, $formation, &$pos): Node {
            $n = new Node($type, $label);
            $n->setFormation($formation);
            $n->setParent($parent);
            $n->setPosition($parent === null ? $pos++ : $parent->getChildren()->count());
            $n->setAttributes($attrs);
            $formation->addNode($n);
            if ($parent !== null) {
                $parent->addChild($n);
            }
            $manager->persist($n);

            return $n;
        };

        $a1 = $mk($t['annee'], 'Année 1', null);
        $s1 = $mk($t['semestre'], 'Semestre 1', $a1);

        // UE complète
        $ue1 = $mk($t['ue'], 'UE 1.1 — Programmation', $s1, ['ects' => 6, 'nature' => 'obligatoire', 'ueType' => 'disciplinaire']);
        $mk($t['ec'], 'Algorithmique', $ue1, [
            'ects' => 3, 'nature' => 'obligatoire',
            'hours' => ['pres' => ['cm' => 12, 'td' => 18], 'te' => 20],
            'mccc' => ['type' => 'CCI'],
        ]);
        $mk($t['ec'], 'Langage C', $ue1, [
            'ects' => 3, 'nature' => 'obligatoire',
            'hours' => ['pres' => ['tp' => 24], 'dist' => ['tp' => 6]],
            'mccc' => ['type' => 'CC_CT'],
        ]);

        // UE incomplète (ECTS manquant sur un EC, pas de MCCC)
        $ue2 = $mk($t['ue'], 'UE 1.2 — Mathématiques', $s1, ['ects' => 6, 'nature' => 'obligatoire']);
        $mk($t['ec'], 'Analyse', $ue2, ['ects' => 3, 'nature' => 'obligatoire', 'hours' => ['pres' => ['cm' => 20]]]);
        $mk($t['ec'], 'Algèbre', $ue2, ['nature' => 'obligatoire']); // volontairement vide

        // Semestre 2 vide
        $mk($t['semestre'], 'Semestre 2', $a1);

        // Années 2 et 3 vides
        $mk($t['annee'], 'Année 2', null);
        $mk($t['annee'], 'Année 3', null);

        // ─── référentiel de compétences (BCC) de la 1re démo ───
        $tbloc = $this->getReference(NodeTypeFixtures::REF_PREFIX.'bloc_competences', NodeType::class);
        $tcomp = $this->getReference(NodeTypeFixtures::REF_PREFIX.'competence', NodeType::class);
        $blocPos = 0;
        $mkBloc = function (string $label, array $comps, array $attrs = []) use ($manager, $formation, $tbloc, $tcomp, &$blocPos): void {
            $bloc = new Node($tbloc, $label);
            $bloc->setFormation($formation);
            $bloc->setPosition($blocPos++);
            $bloc->setAttributes($attrs);
            $formation->addNode($bloc);
            $manager->persist($bloc);
            foreach (array_values($comps) as $i => [$cl, $cd]) {
                $c = new Node($tcomp, $cl);
                $c->setFormation($formation);
                $c->setParent($bloc);
                $c->setPosition($i);
                $c->setAttributes($cd !== '' ? ['description' => $cd] : []);
                $bloc->addChild($c);
                $formation->addNode($c);
                $manager->persist($c);
            }
        };
        $mkBloc('Compétences transversales (RNCP)', [
            ['Communiquer', "S'exprimer à l'écrit et à l'oral en français et en anglais dans un contexte professionnel."],
            ['Travailler en équipe', 'Collaborer en mode projet et rendre compte de son travail.'],
        ], ['transversal' => true]);
        $mkBloc('BC 1 — Développer une application', [
            ['1A', 'Concevoir et implémenter des algorithmes adaptés à un problème.'],
            ['1B', 'Programmer dans plusieurs paradigmes (impératif, objet).'],
            ['1C', 'Tester et documenter un logiciel.'],
        ]);
        $mkBloc('BC 2 — Administrer des données et des systèmes', [
            ['2A', 'Modéliser et interroger une base de données relationnelle.'],
            ['2B', ''],
        ]);

        // ─── 2e démo : multi-parcours avec ramification cohérente sur 3 ans ───
        // Un parcours enfant est une spécialisation qui se sépare du parent : il
        // démarre APRÈS lui. Deux parcours sur la même période sont parallèles.
        $tp = $this->getReference(NodeTypeFixtures::REF_PREFIX.'parcours', NodeType::class);
        $multi = (new Formation('Licence Sciences & Technologies (démo multi-parcours)'))
            ->setDiplome('Licence')
            ->setDomaine('Sciences, technologies, santé')
            ->setComposante('UFR Sciences Exactes et Naturelles')
            ->setMultiParcours(true)
            ->setStructure(['annee', 'semestre', 'ue', 'ec'])
            ->setCalendarSpan(3)
            ->setEctsTotal(180);
        $manager->persist($multi);

        $p = [];
        $specs = [
            // libellé, période de début, période de fin, parcours parent
            ['Portail commun (L1)', 1, 1, null],
            ['Parcours Informatique', 2, 3, 'Portail commun (L1)'],
            ['Parcours Mathématiques', 2, 3, 'Portail commun (L1)'],
            ['Informatique — Données & IA', 3, 3, 'Parcours Informatique'],
            ['Informatique — Génie logiciel', 3, 3, 'Parcours Informatique'],
        ];
        foreach ($specs as $i => [$label, $debut, $fin, $parentLabel]) {
            $node = new Node($tp, $label);
            $node->setFormation($multi);
            $node->setPosition($i);
            $node->setAttributes(['periodeDebut' => $debut, 'periodeFin' => $fin]);
            if ($parentLabel !== null && isset($p[$parentLabel])) {
                $node->setParcoursParent($p[$parentLabel]);
            }
            $multi->addNode($node);
            $manager->persist($node);
            $p[$label] = $node;
        }

        // arbre pédagogique + BCC du parcours « Parcours Informatique »
        $pInfo = $p['Parcours Informatique'];
        $tsemP = $this->getReference(NodeTypeFixtures::REF_PREFIX.'semestre', NodeType::class);
        $tannP = $this->getReference(NodeTypeFixtures::REF_PREFIX.'annee', NodeType::class);
        $tueP = $this->getReference(NodeTypeFixtures::REF_PREFIX.'ue', NodeType::class);
        foreach (['Année 2', 'Année 3'] as $ai => $al) {
            $an = new Node($tannP, $al);
            $an->setFormation($multi);
            $an->setParent($pInfo);
            $an->setPosition($ai);
            $pInfo->addChild($an);
            $multi->addNode($an);
            $manager->persist($an);
            $sm = new Node($tsemP, 'Semestre '.(($ai * 2) + 3));
            $sm->setFormation($multi);
            $sm->setParent($an);
            $sm->setPosition(0);
            $an->addChild($sm);
            $multi->addNode($sm);
            $manager->persist($sm);
            $ueN = new Node($tueP, 'UE Programmation avancée');
            $ueN->setFormation($multi);
            $ueN->setParent($sm);
            $ueN->setPosition(0);
            $ueN->setAttributes(['ects' => 6]);
            $sm->addChild($ueN);
            $multi->addNode($ueN);
            $manager->persist($ueN);
        }

        $bloc = new Node($tbloc, 'BC 1 — Concevoir un système logiciel');
        $bloc->setFormation($multi);
        $bloc->setParent($pInfo);
        $bloc->setPosition(0);
        $pInfo->addChild($bloc);
        $multi->addNode($bloc);
        $manager->persist($bloc);
        foreach ([
            ['C1', 'Analyser un besoin et spécifier une solution.'],
            ['C2', 'Concevoir une architecture logicielle.'],
            ['C3', ''],
        ] as $ci => [$cl, $cd]) {
            $c = new Node($tcomp, $cl);
            $c->setFormation($multi);
            $c->setParent($bloc);
            $c->setPosition($ci);
            $c->setAttributes($cd !== '' ? ['description' => $cd] : []);
            $bloc->addChild($c);
            $multi->addNode($c);
            $manager->persist($c);
        }
        $pInfo->setAttribute('ects', 120);
        $pInfo->setParametre('organisation', [
            'modalitesEnseignement' => 'En présentiel',
            'composante' => 'UFR Sciences Exactes et Naturelles',
            'regimes' => ['FI', 'FI_APP'],
            'modalitesAlternance' => "Rythme 3 semaines entreprise / 2 semaines université en L3.",
            'lieu' => 'Campus principal',
            'respParcours' => 'B. Dupont',
            'dureeValeur' => 2,
            'dureeUnite' => 'Année',
        ]);
        $pInfo->setParametre('presentation', [
            'objectif' => "Former des concepteurs et développeurs de systèmes logiciels complexes.",
            'motsCles' => 'génie logiciel, architecture, développement, tests',
            'resultats' => "Concevoir, implémenter et tester une application de taille moyenne en équipe.",
            'contenu' => "Programmation avancée, architecture logicielle, bases de données, gestion de projet.",
            'langue' => 'Français',
            'niveauLangue' => 'B2',
            'rythme' => 'Alternance',
            'poursuiteEtudes' => "Master informatique, master MIAGE.",
            'debouches' => "Ingénieur d'études, chef de projet junior.",
            'codesRome' => 'M1805 — Études et développement informatique',
        ]);

        // ─── 3e démo : format court (dimension temporelle = semaines) ───
        $tsem = $this->getReference(NodeTypeFixtures::REF_PREFIX.'semaine', NodeType::class);
        $tue = $this->getReference(NodeTypeFixtures::REF_PREFIX.'ue', NodeType::class);
        $court = (new Formation('Certificat Data Analyst (6 semaines)'))
            ->setDiplome('Certificat')
            ->setDomaine('Sciences, technologies, santé')
            ->setComposante('Formation continue')
            ->setMultiParcours(false)
            ->setStructure(['semaine', 'ue', 'ec'])
            ->setCalendarUnit('Semaine')
            ->setCalendarSpan(6)
            ->setEctsTotal(12);
        $manager->persist($court);
        for ($s = 1; $s <= 6; ++$s) {
            $sem = new Node($tsem, "Semaine $s");
            $sem->setFormation($court);
            $sem->setPosition($s - 1);
            $court->addNode($sem);
            $manager->persist($sem);
            $ue = new Node($tue, "Module S$s");
            $ue->setFormation($court);
            $ue->setParent($sem);
            $ue->setPosition(0);
            $ue->setAttributes(['ects' => 2]);
            $sem->addChild($ue);
            $court->addNode($ue);
            $manager->persist($ue);
        }

        $manager->flush();
    }
}
