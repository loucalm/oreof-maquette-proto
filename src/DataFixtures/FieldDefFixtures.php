<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\FieldDef;
use App\Maquette\AttributeCatalog;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

/**
 * Champs de formulaire « socle », amorcés depuis AttributeCatalog::SEED.
 * Tout est ensuite éditable depuis l'éditeur d'un type d'ELP (« Formulaire associé »).
 */
final class FieldDefFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        foreach (AttributeCatalog::SEED as $i => [$key, $label, $tab, $type, $options, $required, $help, $referentielKey]) {
            $field = (new FieldDef($key, $label))
                ->setTab($tab)
                ->setType($type)
                ->setOptions($options)
                ->setReferentielKey($referentielKey)
                ->setRequired($required)
                ->setHelp($help)
                ->setPosition($i * 10)
                ->setSystem(true);
            $manager->persist($field);
        }

        $manager->flush();
    }
}
