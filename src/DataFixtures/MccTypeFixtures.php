<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\MccType;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

/**
 * Les 4 types de MCCC historiques (ex-`AttributeCatalog::MCCC_TYPES`), migrés
 * en catalogue admin-éditable. Comportement radio identique à l'existant ;
 * ensuite entièrement éditable dans /administration/mcc-types.
 *
 * CCI porte 2 profils de démonstration (Licence/Master) pour illustrer le
 * mécanisme : un même type, des règles différentes selon le diplôme — choisi
 * au niveau du template (StructureTemplate::mcccProfiles), pas ici.
 */
final class MccTypeFixtures extends Fixture
{
    private const SEED = [
        // [key, shortLabel, label]
        ['CCI', 'CCI', 'Contrôle continu intégral'],
        ['CC_CT', 'CC + CT', 'Contrôle continu & contrôle terminal'],
        ['CT', 'CT', 'Contrôle terminal'],
        ['CC', 'CC', 'Contrôle continu'],
    ];

    public function load(ObjectManager $manager): void
    {
        foreach (self::SEED as $i => [$key, $short, $label]) {
            $type = (new MccType($key, $label))
                ->setShortLabel($short)
                ->setPosition($i * 10)
                ->setSystem(true);

            if ('CCI' === $key) {
                $type
                    ->setSchema(['collections' => ['evaluations' => ['label' => 'Épreuves']]])
                    ->setProfiles([
                        [
                            'key' => 'licence',
                            'label' => 'Licence',
                            'description' => 'Au moins 3 épreuves, chacune à 50 % maximum, total 100 %.',
                            'rules' => [
                                [
                                    'key' => 'count_licence',
                                    'label' => "Nombre d'épreuves au moins 3",
                                    'severity' => 'error',
                                    'node' => [
                                        'kind' => 'comparison', 'op' => '>=',
                                        'left' => ['kind' => 'aggregate', 'fn' => 'COUNT', 'collection' => 'evaluations'],
                                        'right' => ['kind' => 'literal', 'value' => 3],
                                    ],
                                ],
                                [
                                    'key' => 'each_licence',
                                    'label' => 'Chaque coefficient au plus 50',
                                    'severity' => 'error',
                                    'node' => [
                                        'kind' => 'each', 'collection' => 'evaluations', 'field' => 'weight', 'op' => '<=',
                                        'value' => ['kind' => 'literal', 'value' => 50],
                                    ],
                                ],
                                [
                                    'key' => 'sum_licence',
                                    'label' => 'Somme des coefficients égal à 100',
                                    'severity' => 'error',
                                    'node' => [
                                        'kind' => 'comparison', 'op' => '==',
                                        'left' => ['kind' => 'aggregate', 'fn' => 'SUM', 'collection' => 'evaluations', 'field' => 'weight'],
                                        'right' => ['kind' => 'literal', 'value' => 100],
                                    ],
                                ],
                            ],
                        ],
                        [
                            'key' => 'master',
                            'label' => 'Master',
                            'description' => 'Au moins 2 épreuves, total 100 %, sans plafond par épreuve.',
                            'rules' => [
                                [
                                    'key' => 'count_master',
                                    'label' => "Nombre d'épreuves au moins 2",
                                    'severity' => 'error',
                                    'node' => [
                                        'kind' => 'comparison', 'op' => '>=',
                                        'left' => ['kind' => 'aggregate', 'fn' => 'COUNT', 'collection' => 'evaluations'],
                                        'right' => ['kind' => 'literal', 'value' => 2],
                                    ],
                                ],
                                [
                                    'key' => 'sum_master',
                                    'label' => 'Somme des coefficients égal à 100',
                                    'severity' => 'error',
                                    'node' => [
                                        'kind' => 'comparison', 'op' => '==',
                                        'left' => ['kind' => 'aggregate', 'fn' => 'SUM', 'collection' => 'evaluations', 'field' => 'weight'],
                                        'right' => ['kind' => 'literal', 'value' => 100],
                                    ],
                                ],
                            ],
                        ],
                    ]);
            }

            $manager->persist($type);
        }

        $manager->flush();
    }
}
