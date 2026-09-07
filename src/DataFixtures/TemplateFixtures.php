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

    public function load(ObjectManager $manager): void
    {
        $but = (new StructureTemplate('but', 'BUT (Bachelor Universitaire de Technologie)'))
            ->setDescription("3 années, 2 semestres par année, UE adossées aux compétences, ressources et SAÉ.")
            ->setMultiParcours(false)
            ->setTree($this->butTree());
        $manager->persist($but);

        $licence = (new StructureTemplate('licence_lmd', 'Licence LMD'))
            ->setDescription('3 années · 6 semestres · UE / EC. Squelette générique à adapter.')
            ->setMultiParcours(false)
            ->setTree($this->licenceTree());
        $manager->persist($licence);

        $master = (new StructureTemplate('master_parcours', 'Master à parcours'))
            ->setDescription('Formation multi-parcours : 1 tronc + parcours, 2 années chacun.')
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
            'type' => 'semestre', 'label' => $label,
            'children' => [
                $ue('UE 1 — Compétence 1', [$ec('Ressource R1.01'), $ec('Ressource R1.02'), $ec('SAÉ 1')]),
                $ue('UE 2 — Compétence 2', [$ec('Ressource R2.01'), $ec('Ressource R2.02'), $ec('SAÉ 2')]),
            ],
        ];

        $annee = static fn (string $label, string $s1, string $s2) => [
            'type' => 'annee', 'label' => $label,
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
                    'type' => 'semestre', 'label' => "Semestre $num",
                    'children' => [
                        ['type' => 'ue', 'label' => "UE $num.1", 'attributes' => ['nature' => 'obligatoire'], 'children' => [
                            ['type' => 'ec', 'label' => "EC $num.1.a"],
                            ['type' => 'ec', 'label' => "EC $num.1.b"],
                        ]],
                        ['type' => 'ue', 'label' => "UE $num.2", 'attributes' => ['nature' => 'obligatoire'], 'children' => [
                            ['type' => 'ec', 'label' => "EC $num.2.a"],
                        ]],
                    ],
                ];
            }
            $out[] = ['type' => 'annee', 'label' => "Licence $y", 'children' => $semestres];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function masterTree(): array
    {
        $annee = static fn (string $label) => [
            'type' => 'annee', 'label' => $label,
            'children' => [
                ['type' => 'semestre', 'label' => 'Semestre A', 'children' => [
                    ['type' => 'ue', 'label' => 'UE fondamentale', 'children' => [['type' => 'ec', 'label' => 'Cours magistral']]],
                ]],
                ['type' => 'semestre', 'label' => 'Semestre B', 'children' => [
                    ['type' => 'ue', 'label' => 'UE professionnalisante', 'children' => [['type' => 'ec', 'label' => 'Stage / mémoire']]],
                ]],
            ],
        ];

        return [
            ['type' => 'parcours', 'label' => 'Parcours A', 'children' => [$annee('M1'), $annee('M2')]],
            ['type' => 'parcours', 'label' => 'Parcours B', 'children' => [$annee('M1'), $annee('M2')]],
        ];
    }
}
