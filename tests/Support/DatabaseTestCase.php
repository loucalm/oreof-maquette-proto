<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\Bundle\FixturesBundle\ORMFixtureInterface;
use Doctrine\Common\DataFixtures\Executor\ORMExecutor;
use Doctrine\Common\DataFixtures\Loader;
use Doctrine\Common\DataFixtures\Purger\ORMPurger;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Base des tests qui touchent la base : schéma recréé et fixtures de démo
 * rechargées avant CHAQUE test (SQLite, base `_test` jetable). Les fixtures
 * donnent les NodeType / FieldDef / Referentiel / MccType / templates réels.
 */
abstract class DatabaseTestCase extends KernelTestCase
{
    protected EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
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
    }

    protected function tearDown(): void
    {
        $this->em->close();
        parent::tearDown();
    }
}
