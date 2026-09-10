<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Formation;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * Formations de démo. La maquette d'une formation vit désormais dans 3 colonnes
 * JSON (`parametres`, `dataParcours`, `arbre`) — plus aucune ligne `Node`.
 * On construit ces documents directement.
 */
final class FormationFixtures extends Fixture implements DependentFixtureInterface
{
    public function getDependencies(): array
    {
        return [NodeTypeFixtures::class];
    }

    /** Compteur de nid, réinitialisé par formation. */
    private int $seq = 0;

    public function load(ObjectManager $manager): void
    {
        $manager->persist($this->licenceInfo());
        $manager->persist($this->licenceMulti());
        $manager->persist($this->certificatCourt());
        $manager->flush();
    }

    /**
     * Fabrique un nœud JSON.
     *
     * @param array<string, mixed>       $attrs
     * @param list<array<string, mixed>> $children
     *
     * @return array<string, mixed>
     */
    private function node(string $type, string $label, array $attrs = [], array $children = [], ?string $code = null): array
    {
        $n = ['nid' => 'n'.(++$this->seq), 'type' => $type, 'label' => $label];
        if ($code !== null) {
            $n['code'] = $code;
        }
        if ($attrs !== []) {
            $n['attributes'] = $attrs;
        }
        if ($children !== []) {
            $n['children'] = $children;
        }

        return $n;
    }

    // ─── 1re démo : Licence Informatique, mono-parcours ───

    private function licenceInfo(): Formation
    {
        $this->seq = 0;

        $arbre = [
            $this->node('annee', 'Année 1', [], [
                $this->node('semestre', 'Semestre 1', [], [
                    $this->node('ue', 'UE 1.1 — Programmation', ['ects' => 6, 'nature' => 'obligatoire', 'ueType' => 'disciplinaire'], [
                        $this->node('ec', 'Algorithmique', [
                            'ects' => 3, 'nature' => 'obligatoire', 'ecType' => 'cm', 'competencies' => ['1A', '1B'],
                            'ficheMatiere' => 'Fiche — Algorithmique',
                            'hours' => ['pres' => ['cm' => 12, 'td' => 18], 'te' => 20],
                            'mccc' => ['type' => 'CCI'],
                        ], [], 'INF-ALGO'),
                        $this->node('ec', 'Langage C', [
                            'ects' => 3, 'nature' => 'obligatoire', 'ecType' => 'tp',
                            'ficheMatiere' => 'Fiche — Programmation',
                            'hours' => ['pres' => ['tp' => 24], 'dist' => ['tp' => 6]],
                            'mccc' => ['type' => 'CC_CT'],
                        ], [], 'INF-LANGC'),
                    ]),
                    $this->node('ue', 'UE 1.2 — Mathématiques', ['ects' => 6, 'nature' => 'obligatoire'], [
                        $this->node('ec', 'Analyse', ['ects' => 3, 'nature' => 'obligatoire', 'hours' => ['pres' => ['cm' => 20]]], [], 'MAT-ANA'),
                        $this->node('ec', 'Algèbre', ['nature' => 'obligatoire']), // volontairement vide
                    ]),
                ]),
                $this->node('semestre', 'Semestre 2'),
            ]),
            $this->node('annee', 'Année 2'),
            $this->node('annee', 'Année 3'),

            // ─── référentiel de compétences (BCC), arbre parallèle ───
            $this->bloc('Compétences transversales (RNCP)', [
                ['Communiquer', "S'exprimer à l'écrit et à l'oral en français et en anglais dans un contexte professionnel."],
                ['Travailler en équipe', 'Collaborer en mode projet et rendre compte de son travail.'],
                ['Se documenter', 'Rechercher, évaluer et exploiter une information technique.'],
                ['Agir en responsabilité', 'Prendre en compte les enjeux éthiques, juridiques et de sécurité.'],
            ], true),
            $this->bloc('BC 1 — Développer une application', [
                ['1A', 'Concevoir et implémenter des algorithmes adaptés à un problème.'],
                ['1B', 'Programmer dans plusieurs paradigmes (impératif, objet).'],
                ['1C', 'Tester et documenter un logiciel.'],
                ['1D', 'Utiliser un gestionnaire de versions et un outil de build.'],
                ['1E', 'Concevoir une interface utilisateur simple.'],
            ]),
            $this->bloc('BC 2 — Administrer des données et des systèmes', [
                ['2A', 'Modéliser et interroger une base de données relationnelle.'],
                ['2B', "Administrer un système d'exploitation et ses services."],
                ['2C', 'Configurer un réseau local et diagnostiquer une panne.'],
                ['2D', 'Mettre en place une sauvegarde et une restauration.'],
            ]),
            $this->bloc('BC 3 — Mobiliser les mathématiques pour l’informatique', [
                ['3A', "Utiliser l'algèbre linéaire et l'analyse pour modéliser."],
                ['3B', 'Raisonner avec la logique et les mathématiques discrètes.'],
                ['3C', 'Appliquer les probabilités et les statistiques à des données.'],
            ]),
            $this->bloc('BC 4 — Conduire un projet', [
                ['4A', 'Analyser un besoin et rédiger un cahier des charges.'],
                ['4B', "Planifier et suivre l'avancement d'un projet."],
                ['4C', 'Présenter et soutenir un livrable devant un jury.'],
            ]),
        ];

        return (new Formation('Licence Informatique (démo)'))
            ->setDiplome('Licence')
            ->setDomaine('Sciences, technologies, santé')
            ->setComposante('UFR Sciences Exactes et Naturelles')
            ->setMultiParcours(false)
            ->setStructure(['annee', 'semestre', 'ue', 'ec'])
            ->setEctsTotal(180)
            ->setArbre($arbre)
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
                'objectif' => 'Former des informaticiens polyvalents maîtrisant les fondements de la programmation, des algorithmes, des systèmes et des bases de données.',
                'resultats' => "À l'issue de la formation, l'étudiant conçoit et développe une application, modélise des données et déploie un service.",
                'contenu' => "Programmation, algorithmique, mathématiques pour l'informatique, systèmes et réseaux, bases de données, développement web, projet tutoré.",
                'rythme' => 'Temps plein',
            ])
            ->setParametre('structure', [
                'regimes' => ['FI', 'FC'],
                'langue' => 'Français',
                'modalitesAlternance' => 'Sans objet (formation initiale à temps plein).',
                'poursuiteEtudes' => "Master informatique, master MIAGE, écoles d'ingénieurs sur titre.",
                'debouches' => 'Développeur, administrateur systèmes et bases de données, assistant chef de projet.',
                'codesRome' => 'M1805 — Études et développement informatique',
            ]);
    }

    // ─── 2e démo : multi-parcours avec ramification cohérente sur 3 ans ───

    private function licenceMulti(): Formation
    {
        $this->seq = 0;

        // parcours à plat d'abord, pour référencer les parents par nid
        $names = [
            // libellé => [début, fin, libellé parent]
            'Portail commun (L1)' => [1, 1, null],
            'Parcours Informatique' => [2, 3, 'Portail commun (L1)'],
            'Parcours Mathématiques' => [2, 3, 'Portail commun (L1)'],
            'Informatique — Données & IA' => [3, 3, 'Parcours Informatique'],
            'Informatique — Génie logiciel' => [3, 3, 'Parcours Informatique'],
        ];
        $nid = [];
        foreach (array_keys($names) as $label) {
            $nid[$label] = 'n'.(++$this->seq);
        }

        $parcoursNodes = [];
        foreach ($names as $label => [$debut, $fin, $parentLabel]) {
            $attrs = ['periodeDebut' => $debut, 'periodeFin' => $fin];
            if ($parentLabel !== null) {
                $attrs['parcoursParent'] = $nid[$parentLabel];
            }
            $node = ['nid' => $nid[$label], 'type' => 'parcours', 'label' => $label, 'attributes' => $attrs];

            if ($label === 'Parcours Informatique') {
                $attrs['ects'] = 120;
                $node['attributes'] = $attrs;
                $node['children'] = [
                    $this->node('annee', 'Année 2', [], [
                        $this->node('semestre', 'Semestre 3', [], [
                            $this->node('ue', 'UE Programmation avancée', ['ects' => 6]),
                        ]),
                    ]),
                    $this->node('annee', 'Année 3', [], [
                        $this->node('semestre', 'Semestre 5', [], [
                            $this->node('ue', 'UE Programmation avancée', ['ects' => 6]),
                        ]),
                    ]),
                    $this->bloc('BC 1 — Concevoir un système logiciel', [
                        ['C1', 'Analyser un besoin et spécifier une solution.'],
                        ['C2', 'Concevoir une architecture logicielle.'],
                        ['C3', ''],
                    ]),
                ];
            }
            $parcoursNodes[] = $node;
        }

        $dataParcours = [
            $nid['Parcours Informatique'] => [
                'organisation' => [
                    'modalitesEnseignement' => 'En présentiel',
                    'composante' => 'UFR Sciences Exactes et Naturelles',
                    'regimes' => ['FI', 'FI_APP'],
                    'modalitesAlternance' => 'Rythme 3 semaines entreprise / 2 semaines université en L3.',
                    'lieu' => 'Campus principal',
                    'respParcours' => 'B. Dupont',
                ],
                'presentation' => [
                    'objectif' => 'Former des concepteurs et développeurs de systèmes logiciels complexes.',
                    'motsCles' => 'génie logiciel, architecture, développement, tests',
                    'resultats' => 'Concevoir, implémenter et tester une application de taille moyenne en équipe.',
                    'contenu' => 'Programmation avancée, architecture logicielle, bases de données, gestion de projet.',
                    'langue' => 'Français',
                    'niveauLangue' => 'B2',
                    'rythme' => 'Alternance',
                    'poursuiteEtudes' => 'Master informatique, master MIAGE.',
                    'debouches' => "Ingénieur d'études, chef de projet junior.",
                    'codesRome' => 'M1805 — Études et développement informatique',
                ],
            ],
        ];

        return (new Formation('Licence Sciences & Technologies (démo multi-parcours)'))
            ->setDiplome('Licence')
            ->setDomaine('Sciences, technologies, santé')
            ->setComposante('UFR Sciences Exactes et Naturelles')
            ->setMultiParcours(true)
            ->setStructure(['annee', 'semestre', 'ue', 'ec'])
            ->setCalendarSpan(3)
            ->setEctsTotal(180)
            ->setArbre($parcoursNodes)
            ->setDataParcours($dataParcours);
    }

    // ─── 3e démo : format court (dimension temporelle = semaines) ───

    private function certificatCourt(): Formation
    {
        $this->seq = 0;

        $arbre = [];
        for ($s = 1; $s <= 6; ++$s) {
            $arbre[] = $this->node('semaine', "Semaine $s", [], [
                $this->node('ue', "Module S$s", ['ects' => 2]),
            ]);
        }

        return (new Formation('Certificat Data Analyst (6 semaines)'))
            ->setDiplome('Certificat')
            ->setDomaine('Sciences, technologies, santé')
            ->setComposante('Formation continue')
            ->setMultiParcours(false)
            ->setStructure(['semaine', 'ue', 'ec'])
            ->setCalendarUnit('Semaine')
            ->setCalendarSpan(6)
            ->setEctsTotal(12)
            ->setArbre($arbre);
    }

    /**
     * Bloc de compétences (nœud famille « compétence »).
     *
     * @param list<array{0: string, 1: string}> $comps
     *
     * @return array<string, mixed>
     */
    private function bloc(string $label, array $comps, bool $transversal = false): array
    {
        $children = [];
        foreach ($comps as [$cl, $cd]) {
            $children[] = $this->node('competence', $cl, $cd !== '' ? ['description' => $cd] : []);
        }

        return $this->node('bloc_competences', $label, $transversal ? ['transversal' => true] : [], $children);
    }
}
