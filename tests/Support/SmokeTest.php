<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\NodeType;

final class SmokeTest extends DatabaseTestCase
{
    public function testFixturesLoaded(): void
    {
        self::assertNotNull($this->em->getRepository(NodeType::class)->findOneBy(['key' => 'semestre']));
    }
}
