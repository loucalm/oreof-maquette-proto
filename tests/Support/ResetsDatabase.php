<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\Common\DataFixtures\Executor\ORMExecutor;
use Doctrine\Common\DataFixtures\Loader;
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

    protected function resetDatabase(): void
    {
        $this->em = static::getContainer()->get('doctrine')->getManager();

        $tool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);

        $loader = new Loader();
        foreach (static::getContainer()->get('doctrine.fixtures.loader')->getFixtures() as $fixture) {
            $loader->addFixture($fixture);
        }
        (new ORMExecutor($this->em, new ORMPurger($this->em)))->execute($loader->getFixtures());
        $this->em->clear();
        // les mémos par requête (dépôts, validateurs) ont vu l'état pendant le chargement : on les vide
        static::getContainer()->get('services_resetter')->reset();
    }
}
