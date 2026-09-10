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

    /** Verrous d'un nœud « imposé » : ni suppression, ni changement de parent. */
    private const LOCK = ['delete', 'move'];

    public function load(ObjectManager $manager): void
    {
        $but = (new StructureTemplate('but', 'BUT (Bachelor Universitaire de Technologie)'))
            ->setDescription('3 années, 2 semestres par année, UE adossées aux compétences, ressources et SAÉ.')
            ->setDiplome('BUT')
            ->setMultiParcours(false)
            ->setTree($this->butTree());
        $manager->persist($but);

        $licence = (new StructureTemplate('licence_lmd', 'Licence LMD'))
            ->setDescription('3 années · 6 semestres · UE / EC. Les années et semestres sont imposés par le diplôme.')
            ->setDiplome('Licence')
            ->setMultiParcours(false)
            ->setTree($this->licenceTree());
        $manager->persist($licence);

        $master = (new StructureTemplate('master_parcours', 'Master à parcours'))
            ->setDescription('Formation multi-parcours : 1 tronc + parcours, 2 années chacun.')
            ->setDiplome('Master')
            ->setMultiParcours(true)
            ->setTree($this->masterTree());
        $manager->persist($master);

        $manager->flush();
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
