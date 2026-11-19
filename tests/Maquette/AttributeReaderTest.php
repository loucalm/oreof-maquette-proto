<?php

declare(strict_types=1);

namespace App\Tests\Maquette;

use App\Maquette\AttributeReader;
use PHPUnit\Framework\TestCase;

final class AttributeReaderTest extends TestCase
{
    public function testHoursKeepsMutualiseFlags(): void
    {
        $out = (new AttributeReader())->hours(['pres' => ['cm' => '12'], 'mutualise' => ['pres' => ['cm' => '1']]]);

        self::assertSame(['pres' => ['cm' => 12.0], 'mutualise' => ['pres' => ['cm' => true]]], $out);
    }

    public function testHoursNone(): void
    {
        self::assertSame(['none' => true], (new AttributeReader())->hours(['none' => '1', 'pres' => ['cm' => '3']]));
    }

    public function testMcccDropsQuitusTextWithoutQuitus(): void
    {
        $out = (new AttributeReader())->mccc(['type' => 'cc', 'quitusTexte' => 'texte']);

        self::assertSame(['type' => 'cc'], $out);
    }

    public function testMcccKeepsOptions(): void
    {
        $out = (new AttributeReader())->mccc(['type' => 'cc', 'sansNote' => '1', 'assiduite' => '1', 'quitus' => '1', 'quitusTexte' => ' Quitus ']);

        self::assertSame(['type' => 'cc', 'sansNote' => true, 'assiduite' => true, 'quitus' => true, 'quitusTexte' => 'Quitus'], $out);
    }

    public function testEmptyRowsIgnored(): void
    {
        $out = (new AttributeReader())->mccc(['type' => 'cc', 'evaluations' => [['type' => '', 'weight' => ''], ['type' => 'ecrit', 'weight' => '50']]]);

        self::assertSame(['type' => 'cc', 'evaluations' => [['type' => 'ecrit', 'weight' => 50.0]]], $out);
    }

    public function testNumber(): void
    {
        $r = new AttributeReader();
        self::assertNull($r->number(''));
        self::assertNull($r->number(null));
        self::assertSame(3.5, $r->number('3.5'));
    }
}
