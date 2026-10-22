<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Diplome\Spec\DurationMath;
use App\Diplome\StructureInstantiator;
use App\Entity\Formation;
use App\Entity\TypeDiplome;
use App\Maquette\Doc\MaquetteDoc;
use App\Maquette\Doc\TreeNode;
use App\Maquette\Maquette;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * Formations de démo. Les formations typées (Licence, Licence multi-parcours, BUT) sont créées comme
 * à l'écran : structure de départ générée par StructureInstantiator d'après la version du type de
 * diplôme (donc dans ses bornes), puis complétée. Le certificat court
 * reste volontairement sans type de diplôme (structure libre, dimension temporelle en semaines).
 */
final class FormationFixtures extends Fixture implements DependentFixtureInterface
{
    public function getDependencies(): array
    {
        return [NodeTypeFixtures::class, TypeDiplomeFixtures::class];
    }

    /** Compteur de nid, réinitialisé par formation. */
    private int $seq = 0;

    public function __construct(
        private readonly Maquette $maquette,
        private readonly StructureInstantiator $instantiator,
    ) {
    }

    private TypeDiplome $licenceType;
    private TypeDiplome $butType;
    private ObjectManager $manager;

    public function load(ObjectManager $manager): void
    {
        $this->manager = $manager;
        $this->licenceType = $this->getReference(TypeDiplomeFixtures::REF_PREFIX.'L', TypeDiplome::class);
        $this->butType = $this->getReference(TypeDiplomeFixtures::REF_PREFIX.'BUT', TypeDiplome::class);

        $this->licenceInfo();
        $this->licenceMulti();
        $this->butInfo();

        // formation non typée : arbre JSON direct, puis amorce du cache de progression (Formation::stats)
        $certificat = $this->certificatCourt();
        $manager->persist($certificat);
        $manager->flush();
        $this->maquette->refreshStats($certificat);
    }

    /**
     * Crée une formation typée avec sa structure de départ (comme la création à l'écran) et ouvre sa maquette.
     *
     * @return array{0: Formation, 1: MaquetteDoc}
     */
    private function start(string $name, TypeDiplome $type, int $duration, bool $avecParcours): array
    {
        $version = $type->latestVersion() ?? throw new \LogicException('Type de démo sans version.');
        $formation = (new Formation($name))
            ->setTypeDiplome($type)
            ->setAnneeUniversitaire(TypeDiplomeFixtures::YEAR)
            ->setDomaine('Sciences, technologies, santé')
            ->setComposante('UFR Sciences Exactes et Naturelles')
            ->setCalendarSpan(intdiv($duration, DurationMath::factor($version->config()->structure))); // durée en nœuds du niveau de durée, période en années
        $this->manager->persist($formation);
        $this->manager->flush();

        $doc = $this->maquette->open($formation);
        $this->instantiator->instantiate($doc, $version, $duration, $avecParcours);

        return [$formation, $doc];
    }

    /** @param array<string, mixed> $attrs */
    private function fill(TreeNode $node, string $label, array $attrs = [], ?string $code = null): TreeNode
    {
        $node->setLabel($label)->setCode($code);
        foreach ($attrs as $k => $v) {
            $node->setAttribute($k, $v);
        }

        return $node;
    }

    /**
     * Ajoute un EC sous un nœud.
     *
     * @param array<string, mixed> $attrs
     */
    private function ec(MaquetteDoc $doc, TreeNode $parent, string $label, array $attrs = [], ?string $code = null): TreeNode
    {
        return $this->fill($doc->addNode($parent->nid, 'ec'), $label, ['nature' => 'obligatoire'] + $attrs, $code);
    }

    /** Donne 10 ECTS à chaque UE sans valeur : 3 UE par semestre font les 30 ECTS attendus. */
    private function defaultEcts(MaquetteDoc $doc): void
    {
        foreach ($doc->allNodes() as $node) {
            if ('ue' === $node->typeKey && null === $node->getAttribute('ects')) {
                $node->setAttribute('ects', 10);
            }
        }
    }

    /** Ajoute un parcours (périodes `debut`–`fin`, rattaché à son parent de ramification) avec ses années de départ. */
    private function parcours(MaquetteDoc $doc, string $label, int $debut, int $fin, TreeNode $parent, int $ects): TreeNode
    {
        $parcours = $doc->addNode(null, 'parcours', $label);
        $parcours->setAttribute('periodeDebut', $debut)->setAttribute('periodeFin', $fin)->setAttribute('ects', $ects);
        $parcours->setParcoursParent($parent);
        for ($y = $debut; $y <= $fin; ++$y) {
            $annee = $doc->addNode($parcours->nid, 'annee');
            for ($s = 0; $s < 2; ++$s) {
                $semestre = $doc->addNode($annee->nid, 'semestre');
                for ($u = 0; $u < 3; ++$u) {
                    $doc->addNode($semestre->nid, 'ue');
                }
            }
        }

        return $parcours;
    }

    /** Semestres, dans l'ordre, d'une portée (la racine en mono-parcours, un parcours sinon). */
    private function semestres(MaquetteDoc $doc, ?TreeNode $parcours = null): array
    {
        $out = [];
        foreach ($parcours?->getChildren() ?? $doc->pedagogicalRoots() as $annee) {
            foreach ($annee->getChildren() as $semestre) {
                $out[] = $semestre;
            }
        }

        return $out;
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

    // ─── 1re démo : Licence Informatique, sans parcours ───

    private function licenceInfo(): void
    {
        [$formation, $doc] = $this->start('Licence Informatique (démo)', $this->licenceType, 3, false);

        // semestre 1 renseigné ; les autres gardent la structure de départ
        [$prog, $maths, $langues] = $this->semestres($doc)[0]->getChildren();
        $this->fill($prog, 'Programmation', ['ects' => 10, 'ueType' => 'disciplinaire']);
        $this->ec($doc, $prog, 'Algorithmique', [
            'ects' => 5, 'ecType' => 'cm', 'competencies' => ['1A', '1B'],
            'ficheMatiere' => 'Fiche — Algorithmique',
            'hours' => ['pres' => ['cm' => 12, 'td' => 18], 'te' => 20],
            'mccc' => ['type' => 'CCI'],
        ], 'INF-ALGO');
        $this->ec($doc, $prog, 'Langage C', [
            'ects' => 5, 'ecType' => 'tp',
            'ficheMatiere' => 'Fiche — Programmation',
            'hours' => ['pres' => ['tp' => 24], 'dist' => ['tp' => 6]],
            'mccc' => ['type' => 'CC_CT'],
        ], 'INF-LANGC');
        $this->fill($maths, 'Mathématiques', ['ects' => 10, 'nature' => 'obligatoire']);
        $this->ec($doc, $maths, 'Analyse', ['ects' => 5, 'hours' => ['pres' => ['cm' => 20]]], 'MAT-ANA');
        $this->ec($doc, $maths, 'Algèbre'); // volontairement vide
        $this->fill($langues, 'Anglais et expression', ['ects' => 10, 'nature' => 'obligatoire']);
        $this->defaultEcts($doc);

        // ─── référentiel de compétences (BCC), arbre parallèle ───
        $this->bloc($doc, null, 'Compétences transversales (RNCP)', [
            ['Communiquer', "S'exprimer à l'écrit et à l'oral en français et en anglais dans un contexte professionnel."],
            ['Travailler en équipe', 'Collaborer en mode projet et rendre compte de son travail.'],
            ['Se documenter', 'Rechercher, évaluer et exploiter une information technique.'],
            ['Agir en responsabilité', 'Prendre en compte les enjeux éthiques, juridiques et de sécurité.'],
        ], true);
        $this->bloc($doc, null, 'Développer une application', [
            ['1A', 'Concevoir et implémenter des algorithmes adaptés à un problème.'],
            ['1B', 'Programmer dans plusieurs paradigmes (impératif, objet).'],
            ['1C', 'Tester et documenter un logiciel.'],
            ['1D', 'Utiliser un gestionnaire de versions et un outil de build.'],
            ['1E', 'Concevoir une interface utilisateur simple.'],
        ]);
        $this->bloc($doc, null, 'Administrer des données et des systèmes', [
            ['2A', 'Modéliser et interroger une base de données relationnelle.'],
            ['2B', "Administrer un système d'exploitation et ses services."],
            ['2C', 'Configurer un réseau local et diagnostiquer une panne.'],
            ['2D', 'Mettre en place une sauvegarde et une restauration.'],
        ]);
        $this->bloc($doc, null, 'Mobiliser les mathématiques pour l’informatique', [
            ['3A', "Utiliser l'algèbre linéaire et l'analyse pour modéliser."],
            ['3B', 'Raisonner avec la logique et les mathématiques discrètes.'],
            ['3C', 'Appliquer les probabilités et les statistiques à des données.'],
        ]);
        $this->bloc($doc, null, 'Conduire un projet', [
            ['4A', 'Analyser un besoin et rédiger un cahier des charges.'],
            ['4B', "Planifier et suivre l'avancement d'un projet."],
            ['4C', 'Présenter et soutenir un livrable devant un jury.'],
        ]);

        $formation->setEctsTotal(180);
        $doc->setFormationParam('organisation', [
                'contacts' => "Secrétariat pédagogique — licence.info@univ-demo.fr\n+33 (0)1 23 45 67 89",
                'mention' => 'Informatique',
                'niveauEntree' => 'Baccalauréat',
                'niveauSortie' => 'Bac+3',
                'rncp' => 'oui',
                'codeRncp' => '24514',
                'codeApogee' => 'L-INFO-01',
                'respMention' => 'A. Martin',
                'coRespMention' => 'C. Bernard',
        ]);
        $doc->setFormationParam('presentation', [
                'objectif' => 'Former des informaticiens polyvalents maîtrisant les fondements de la programmation, des algorithmes, des systèmes et des bases de données.',
                'resultats' => "À l'issue de la formation, l'étudiant conçoit et développe une application, modélise des données et déploie un service.",
                'contenu' => "Programmation, algorithmique, mathématiques pour l'informatique, systèmes et réseaux, bases de données, développement web, projet tutoré.",
                'rythme' => 'Temps plein',
        ]);
        // fiche parcours de la formation sans parcours (parcours unique, cf. App\Formulaire\Fiche\MonoParcoursFiche)
        $doc->setFormationParam('parcours.organisation', [
                'regimes' => ['FI', 'FC'],
                'modalitesAlternance' => 'Sans objet (formation initiale à temps plein).',
            ]);
        $doc->setFormationParam('parcours.presentation', [
                'langue' => 'Français',
                'poursuiteEtudes' => "Master informatique, master MIAGE, écoles d'ingénieurs sur titre.",
                'debouches' => 'Développeur, administrateur systèmes et bases de données, assistant chef de projet.',
                'codesRome' => 'M1805 — Études et développement informatique',
            ]);
        $this->maquette->save($formation, $doc, 'Démo');
    }

    // ─── 2e démo : avec parcours et ramification (portail › parcours › sous-parcours) ───
    // La durée du diplôme se mesure par cursus : périodes du parcours + celles de ses parents (portail compris).

    private function licenceMulti(): void
    {
        [$formation, $doc] = $this->start('Licence Sciences & Technologies (démo avec parcours)', $this->licenceType, 3, true);

        // le parcours créé avec la structure de départ devient le portail commun : une seule année
        $portail = $doc->parcoursNodes()[0];
        foreach (\array_slice($portail->getChildren(), 1) as $annee) {
            $doc->removeNode($annee, true);
        }
        $this->fill($portail, 'Portail commun (L1)', ['periodeDebut' => 1, 'periodeFin' => 1, 'ects' => 60]);

        $info = $this->parcours($doc, 'Parcours Informatique', 2, 2, $portail, 120);
        $this->parcours($doc, 'Parcours Mathématiques', 2, 3, $portail, 120);
        $this->parcours($doc, 'Informatique — Données & IA', 3, 3, $info, 60);
        $this->parcours($doc, 'Informatique — Génie logiciel', 3, 3, $info, 60);

        $semestres = $this->semestres($doc, $info);
        $this->fill($semestres[0]->getChildren()[0], 'UE Programmation avancée', ['ects' => 10]);
        $this->defaultEcts($doc);
        $this->bloc($doc, $info, 'Concevoir un système logiciel', [
            ['C1', 'Analyser un besoin et spécifier une solution.'],
            ['C2', 'Concevoir une architecture logicielle.'],
            ['C3', ''],
        ]);

        $doc->setParcoursParam($info->nid, 'organisation', [
            'modalitesEnseignement' => 'En présentiel',
            'composanteInscription' => 'UFR Sciences Exactes et Naturelles',
            'regimes' => ['FI', 'FI_APP'],
            'modalitesAlternance' => 'Rythme 3 semaines entreprise / 2 semaines université en L3.',
            'lieu' => 'Campus principal',
            'respParcours' => 'B. Dupont',
        ]);
        $doc->setParcoursParam($info->nid, 'presentation', [
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
        ]);

        $formation->setEctsTotal(180);
        $this->maquette->save($formation, $doc, 'Démo');
    }

    // ─── 4e démo : BUT, avec le référentiel national de compétences (lecture seule) ───

    private function butInfo(): void
    {
        [$formation, $doc] = $this->start('BUT Informatique (démo)', $this->butType, 6, false);

        $semestre = $this->semestres($doc)[0];
        $ue = $this->fill($semestre->getChildren()[0], 'UE 1.1 — Réaliser un développement d’application', ['ects' => 6, 'nature' => 'obligatoire']);
        $this->ec($doc, $ue, 'Ressource R1.01 — Initiation au développement', ['natureElement' => 'ressource', 'ects' => 10]);
        $this->ec($doc, $ue, 'SAÉ 1.01 — Implémentation d’un besoin client', ['natureElement' => 'sae', 'ects' => 10]);
        // un stage dans les semestres 2, 4 et 6 (règles « Au moins 1 EC Stage » du BUT)
        foreach ([2, 4, 6] as $n) {
            $this->ec($doc, $this->semestres($doc)[$n - 1]->getChildren()[0], sprintf('Stage S%d', $n), ['natureElement' => 'stage']);
        }
        foreach ($this->semestres($doc) as $s) {
            foreach ($s->getChildren() as $u) {
                if (null === $u->getAttribute('ects')) {
                    $u->setAttribute('ects', 6); // 5 UE × 6 = 30 ECTS par semestre
                }
            }
        }

        $formation->setEctsTotal(180);
        $doc->setFormationParam('bccReferentiel', ['competences' => $this->butReferentiel()]);
        $this->maquette->save($formation, $doc, 'Démo');
    }

    /**
     * Référentiel national de démonstration (dans ORéOF : synchronisé depuis l'API des référentiels) —
     * 2 compétences, 3 niveaux (1re à 3e année), quelques apprentissages critiques.
     *
     * @return list<array<string, mixed>>
     */
    private function butReferentiel(): array
    {
        $niveau = static fn (int $c, int $n, string $libelle, array $acs) => [
            'ordre' => $n,
            'annee' => 'BUT'.$n,
            'libelle' => $libelle,
            'acs' => array_map(
                static fn (int $i, string $l) => ['code' => sprintf('AC%d%d.%02d', $n, $c, $i + 1), 'libelle' => $l],
                array_keys($acs),
                $acs,
            ),
        ];

        return [
            [
                'numero' => 1, 'nomCourt' => 'Réaliser', 'libelle' => 'Réaliser un développement d’application',
                'niveaux' => [
                    $niveau(1, 1, 'Développer des applications simples', ['Implémenter une conception simple', 'Tester le bon fonctionnement d’un programme']),
                    $niveau(1, 2, 'Partir des exigences et aller jusqu’à une application complète', ['Concevoir une application à partir d’un cahier des charges', 'Intégrer des composants existants']),
                    $niveau(1, 3, 'Adapter des applications sur un ensemble de supports', ['Choisir une architecture adaptée au support', 'Assurer la maintenance évolutive']),
                ],
            ],
            [
                'numero' => 2, 'nomCourt' => 'Optimiser', 'libelle' => 'Optimiser des applications',
                'niveaux' => [
                    $niveau(2, 1, 'Appréhender et construire des algorithmes', ['Analyser un problème avec méthode', 'Comparer des algorithmes pour un même problème']),
                    $niveau(2, 2, 'Sélectionner les algorithmes adéquats', ['Choisir des structures de données adaptées', 'Mesurer la performance d’un programme']),
                    $niveau(2, 3, 'Analyser et optimiser des applications', ['Profiler une application', 'Justifier les choix d’optimisation']),
                ],
            ],
        ];
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
            ->setAvecParcours(false)
            ->setStructure(['semaine', 'ue', 'ec'])
            ->setCalendarUnit('Semaine')
            ->setCalendarSpan(6)
            ->setEctsTotal(12)
            ->setArbre($arbre);
    }

    /**
     * Bloc de compétences (nœud famille « compétence »), à la racine ou sous un parcours.
     *
     * @param list<array{0: string, 1: string}> $comps
     */
    private function bloc(MaquetteDoc $doc, ?TreeNode $parcours, string $label, array $comps, bool $transversal = false): void
    {
        $bloc = $doc->addNode($parcours?->nid, 'bloc_competences', $label);
        if ($transversal) {
            $bloc->setAttribute('transversal', true);
        }
        foreach ($comps as [$cl, $cd]) {
            $c = $doc->addNode($bloc->nid, 'competence', $cl);
            if ('' !== $cd) {
                $c->setAttribute('description', $cd);
            }
        }
    }
}
