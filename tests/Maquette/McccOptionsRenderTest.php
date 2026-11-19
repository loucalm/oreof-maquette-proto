<?php

declare(strict_types=1);

namespace App\Tests\Maquette;

use App\Twig\MaquetteExtension;
use App\Tests\Support\FormationFactory;
use App\Tests\Support\ResetsDatabase;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class McccOptionsRenderTest extends KernelTestCase
{
    use ResetsDatabase;

    public function testNoVersionMeansAllOptionsOff(): void
    {
        self::bootKernel();
        $this->resetDatabase();
        $formation = FormationFactory::create(static::getContainer())->fromType('L', 3, avecParcours: true, withLeaves: true);
        $node = static::getContainer()->get(\App\Maquette\Maquette::class)->open($formation)->allNodes()[0];
        $formation->setTypeDiplome(null);

        self::assertSame(
            ['quitus' => false, 'assiduite' => false, 'sansNote' => false],
            static::getContainer()->get(MaquetteExtension::class)->mcccOptions($node),
        );
    }
}
