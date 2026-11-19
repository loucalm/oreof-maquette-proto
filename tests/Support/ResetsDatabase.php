<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\DataFixtures\FicheMatiereFixtures;
use Doctrine\Common\DataFixtures\Executor\ORMExecutor;
use Doctrine\Common\DataFixtures\FixtureInterface;
use Doctrine\Common\DataFixtures\Purger\ORMPurger;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;

/**
 * Recrée le schéma de la base de test (SQLite `var/test.db`) et recharge les
 * fixtures de démo : NodeType / FieldDef / Referentiel / MccType / templates réels.
 * Le noyau doit déjà être démarré.
 */
trait ResetsDatabase
{
    protected EntityManagerInterface $em;

    /** Jeu de fiches de démo chargé seulement par les tests qui le vérifient (requêtes globales). */
    protected bool $withDemoFiches = false;

    protected function resetDatabase(?bool $withDemoFiches = null): void
    {
        $this->em = static::getContainer()->get('doctrine')->getManager();

        $tool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);

        // Le loader du conteneur connaît les dépendances et les services (constructeurs) des fixtures ;
        // un Loader nu recréerait les dépendances sans leurs arguments.
        $loader = static::getContainer()->get('doctrine.fixtures.loader');
        $fixtures = $loader->getFixtures();
        if (!($withDemoFiches ?? $this->withDemoFiches)) {
            $fixtures = array_values(array_filter(
                $fixtures,
                static fn (FixtureInterface $fixture): bool => !$fixture instanceof FicheMatiereFixtures,
            ));
        }
        (new ORMExecutor($this->em, new ORMPurger($this->em)))->execute($fixtures);
        $this->em->clear();
        // les mémos par requête (dépôts, validateurs) ont vu l'état pendant le chargement : on les vide
        static::getContainer()->get('services_resetter')->reset();
    }
}
