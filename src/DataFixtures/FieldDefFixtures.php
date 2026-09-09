<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\FieldDef;
use App\Maquette\AttributeCatalog;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

/**
 * Champs de formulaire « socle », amorcés depuis AttributeCatalog::SEED.
 * Tout est ensuite éditable dans /champs.
 */
final class FieldDefFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        foreach (AttributeCatalog::SEED as $i => [$key, $label, $tab, $category, $type, $options, $required, $help]) {
            $field = (new FieldDef($key, $label))
                ->setTab($tab)
                ->setCategory($category)
                ->setType($type)
                ->setOptions($options)
                ->setRequired($required)
                ->setHelp($help)
                ->setPosition($i * 10)
                ->setSystem(true);
            $manager->persist($field);
        }

        $manager->flush();
    }
}
