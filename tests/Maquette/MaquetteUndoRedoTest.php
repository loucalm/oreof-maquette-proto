<?php

declare(strict_types=1);

namespace App\Tests\Maquette;

use App\Maquette\Maquette;
use App\Tests\Support\DatabaseTestCase;
use App\Tests\Support\FormationFactory;

final class MaquetteUndoRedoTest extends DatabaseTestCase
{
    private FormationFactory $factory;
    private Maquette $maquette;

    protected function setUp(): void
    {
        parent::setUp();
        $this->factory = FormationFactory::create(static::getContainer());
        $this->maquette = static::getContainer()->get(Maquette::class);
    }

    private function addYear(\App\Entity\Formation $formation): int
    {
        $doc = $this->maquette->open($formation);
        $doc->addNode(null, 'annee', '');
        $this->maquette->save($formation, $doc);

        return \count($doc->allNodes());
    }

    public function testSaveRecordsARevisionAndEnablesUndo(): void
    {
        $formation = $this->factory->fromType('L', 3, withLeaves: true);
        $this->addYear($formation);

        self::assertTrue($this->maquette->canUndo($formation));
        self::assertNotSame([], $this->maquette->history($formation));
    }

    public function testUndoRestoresPreviousTreeThenRedoReappliesIt(): void
    {
        $formation = $this->factory->fromType('L', 3, withLeaves: true);
        $before = \count($this->maquette->open($formation)->allNodes());
        $after = $this->addYear($formation);
        self::assertSame($before + 1, $after);

        self::assertTrue($this->maquette->undo($formation));
        self::assertCount($before, $this->maquette->open($formation)->allNodes());
        self::assertTrue($this->maquette->canRedo($formation));

        self::assertTrue($this->maquette->redo($formation));
        self::assertCount($after, $this->maquette->open($formation)->allNodes());
        self::assertFalse($this->maquette->canRedo($formation));
    }

    public function testANewChangeInvalidatesRedo(): void
    {
        $formation = $this->factory->fromType('L', 3, withLeaves: true);
        $this->addYear($formation);
        $this->maquette->undo($formation);
        self::assertTrue($this->maquette->canRedo($formation));

        $this->addYear($formation);

        self::assertFalse($this->maquette->canRedo($formation));
    }

    public function testUndoWithoutHistoryReturnsFalse(): void
    {
        $formation = new \App\Entity\Formation('Vide');
        $this->em->persist($formation);
        $this->em->flush();

        self::assertFalse($this->maquette->undo($formation));
        self::assertFalse($this->maquette->redo($formation));
    }
}
