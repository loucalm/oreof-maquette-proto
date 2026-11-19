<?php

declare(strict_types=1);

namespace App\Tests\FicheMatiere;

use App\Entity\NodeType;
use App\FicheMatiere\SourcePinner;
use App\Maquette\Doc\TreeNode;
use App\Repository\NodeTypeRepository;
use App\Tests\Support\DatabaseTestCase;

/** Écriture explicite des sources `ec` quand le contexte d'un EC change (spec §7.2 bis). */
final class SourcePinnerTest extends DatabaseTestCase
{
    private SourcePinner $pinner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pinner = static::getContainer()->get(SourcePinner::class);
    }

    public function testPinFilledIgnoresEmptyValues(): void
    {
        $hours = ['pres' => ['cm' => 12.0]];
        $ec = $this->ec([
            'hours' => $hours,
            'ects' => 2.0,
            'mccc' => [],
            'competencies' => [],
            'sources' => ['mccc' => 'ec'],
        ]);

        self::assertTrue($this->pinner->pinFilled($ec));
        self::assertSame(['mccc' => 'ec', 'hours' => 'ec', 'ects' => 'ec'], $ec->getAttribute('sources'));
        self::assertSame($hours, $ec->getAttribute('hours'));
        // les valeurs vides ne reçoivent pas de source, et un appel sans changement ne réécrit rien
        self::assertFalse($this->pinner->pinFilled($ec));

        $empty = $this->ec(['hours' => [], 'ects' => '', 'mccc' => null, 'competencies' => []]);
        self::assertFalse($this->pinner->pinFilled($empty));
        self::assertNull($empty->getAttribute('sources'));
    }

    /** @param array<string, mixed> $attributes */
    private function ec(array $attributes): TreeNode
    {
        $type = static::getContainer()->get(NodeTypeRepository::class)->findOneByKey('ec');
        self::assertInstanceOf(NodeType::class, $type);

        $ec = new TreeNode('ec1', 'ec', 'EC', attributes: $attributes);
        $ec->bindType($type);

        return $ec;
    }
}
