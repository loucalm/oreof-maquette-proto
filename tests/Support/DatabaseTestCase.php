<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Base des tests de services qui touchent la base : schéma et fixtures
 * rechargés avant CHAQUE test.
 */
abstract class DatabaseTestCase extends KernelTestCase
{
    use ResetsDatabase;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->resetDatabase();
    }

    protected function tearDown(): void
    {
        $this->em->close();
        parent::tearDown();
    }
}
