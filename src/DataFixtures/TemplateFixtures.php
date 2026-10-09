<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\StructureTemplate;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * Modèles de structure de départ. Ce sont de simples arbres JSON, instanciés
 * puis modifiables. Le modèle « BUT » sert d'exemple.
 */
final class TemplateFixtures extends Fixture implements DependentFixtureInterface
{
    public function getDependencies(): array
    {
        return [NodeTypeFixtures::class];
    }

    /** Verrous d'un nœud « imposé » : figé (ni ajout d'un autre du même type ici, ni suppression, ni duplication), mais déplaçable. */
    private const LOCK = ['add', 'delete', 'duplicate'];

    public function load(ObjectManager $manager): void
    {
        $but = (new StructureTemplate('but', 'BUT (Bachelor Universitaire de Technologie)'))
            ->setDescription('3 années, 2 semestres par année, UE adossées aux compétences, ressources et SAÉ.')
            ->setDiplome('BUT')
            ->setAvecParcours(false)
            ->setStructure(['annee', 'semestre', 'ue', 'ec'])
            ->setCalendarUnit('Année')
            ->setCalendarSpan(3)
            ->setTree($this->butTree())
            ->setBccFormat(StructureTemplate::BCC_BUT)
            ->setBccReferentiel($this->butReferentiel());
        $manager->persist($but);

        $licence = (new StructureTemplate('licence_lmd', 'Licence LMD'))
            ->setDescription('3 années · 6 semestres · UE / EC. Les années et semestres sont imposés par le diplôme.')
            ->setDiplome('Licence')
            ->setAvecParcours(false)
            ->setStructure(['annee', 'semestre', 'ue', 'ec'])
            ->setCalendarUnit('Année')
            ->setCalendarSpan(3)
            ->setMcccProfiles(['CCI' => 'licence'])
            ->setTree($this->licenceTree());
        $manager->persist($licence);

        $master = (new StructureTemplate('master_parcours', 'Master à parcours'))
            ->setDescription('Formation avec parcours : 1 tronc + parcours, 2 années chacun.')
            ->setDiplome('Master')
            ->setAvecParcours(true)
            ->setStructure(['annee', 'semestre', 'ue', 'ec'])
            ->setCalendarUnit('Année')
            ->setCalendarSpan(2)
            ->setMcccProfiles(['CCI' => 'master'])
            ->setTree($this->masterTree());
        $manager->persist($master);

        $manager->flush();
    }

    /**
     * Référentiel national BUT de démonstration (dans ORéOF : synchronisé depuis
     * l'API des référentiels) — 2 compétences, 3 niveaux (BUT1 à BUT3), quelques AC.
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

    /** @return list<array<string, mixed>> */
    private function butTree(): array
    {
        $ue = static fn (string $label, array $ecs) => [
            'type' => 'ue', 'label' => $label,
            'attributes' => ['nature' => 'obligatoire'],
            'children' => $ecs,
        ];
        $ec = static fn (string $label) => ['type' => 'ec', 'label' => $label, 'attributes' => ['nature' => 'obligatoire']];

        $semestre = static fn (string $label) => [
            'type' => 'semestre', 'label' => $label, 'locked' => self::LOCK,
            'children' => [
                $ue('UE 1 — Compétence 1', [$ec('Ressource R1.01'), $ec('Ressource R1.02'), $ec('SAÉ 1')]),
                $ue('UE 2 — Compétence 2', [$ec('Ressource R2.01'), $ec('Ressource R2.02'), $ec('SAÉ 2')]),
            ],
        ];

        $annee = static fn (string $label, string $s1, string $s2) => [
            'type' => 'annee', 'label' => $label, 'locked' => self::LOCK,
            'children' => [$semestre($s1), $semestre($s2)],
        ];

        return [
            $annee('BUT 1', 'Semestre 1', 'Semestre 2'),
            $annee('BUT 2', 'Semestre 3', 'Semestre 4'),
            $annee('BUT 3', 'Semestre 5', 'Semestre 6'),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function licenceTree(): array
    {
        $out = [];
        for ($y = 1; $y <= 3; ++$y) {
            $semestres = [];
            for ($s = 1; $s <= 2; ++$s) {
                $num = ($y - 1) * 2 + $s;
                $semestres[] = [
                    'type' => 'semestre', 'label' => "Semestre $num", 'locked' => self::LOCK,
                    'children' => [
                        ['type' => 'ue', 'label' => 'UE disciplinaire', 'attributes' => ['nature' => 'obligatoire'], 'children' => [
                            ['type' => 'ec', 'label' => 'EC à compléter'],
                        ]],
                    ],
                ];
            }
            $out[] = ['type' => 'annee', 'label' => "Licence $y", 'locked' => self::LOCK, 'children' => $semestres];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function masterTree(): array
    {
        $annee = static fn (string $label) => [
            'type' => 'annee', 'label' => $label, 'locked' => self::LOCK,
            'children' => [
                ['type' => 'semestre', 'label' => 'Semestre A', 'locked' => self::LOCK, 'children' => [
                    ['type' => 'ue', 'label' => 'UE fondamentale', 'children' => [['type' => 'ec', 'label' => 'Cours magistral']]],
                ]],
                ['type' => 'semestre', 'label' => 'Semestre B', 'locked' => self::LOCK, 'children' => [
                    ['type' => 'ue', 'label' => 'UE professionnalisante', 'children' => [['type' => 'ec', 'label' => 'Stage / mémoire']]],
                ]],
            ],
        ];

        return [
            ['type' => 'parcours', 'label' => 'Parcours type', 'locked' => self::LOCK, 'children' => [$annee('M1'), $annee('M2')]],
        ];
    }
}
