<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\MccType;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

/**
 * Les 4 types de MCCC historiques (ex-`AttributeCatalog::MCCC_TYPES`), migrés
 * en catalogue admin-éditable. Offerts partout (`diplomes: []`), sans règle
 * ni collection d'épreuves — comportement radio identique à l'existant.
 * Ensuite entièrement éditable dans /administration/mcc-types.
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
            $manager->persist($type);
        }

        $manager->flush();
    }
}
