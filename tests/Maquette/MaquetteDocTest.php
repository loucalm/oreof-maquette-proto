<?php

declare(strict_types=1);

namespace App\Tests\Maquette;

use App\Maquette\Maquette;
use App\Tests\Support\DatabaseTestCase;
use App\Tests\Support\FormationFactory;

final class MaquetteDocTest extends DatabaseTestCase
{
    private FormationFactory $factory;
    private Maquette $maquette;

    protected function setUp(): void
    {
        parent::setUp();
        $this->factory = FormationFactory::create(static::getContainer());
        $this->maquette = static::getContainer()->get(Maquette::class);
    }

    public function testRemoveNodeRefusesLockedNode(): void
    {
        $doc = $this->maquette->open($this->factory->fromTemplate('licence_lmd'));
        $annee = $this->factory->first($doc, 'annee');
        self::assertTrue($annee->isLocked('delete'));

        self::assertFalse($doc->removeNode($annee));
        self::assertNotNull($doc->node($annee->nid));
    }

    public function testRemoveNodeForceRemovesLockedNodeAndItsSubtree(): void
    {
        $doc = $this->maquette->open($this->factory->fromTemplate('licence_lmd'));
        $annee = $this->factory->first($doc, 'annee');
        $descendantNid = $annee->children[0]->nid;

        self::assertTrue($doc->removeNode($annee, true));
        self::assertNull($doc->node($annee->nid));
        self::assertNull($doc->node($descendantNid));
    }

    public function testRemoveNodeRemovesFreeNodeWithDescendants(): void
    {
        $doc = $this->maquette->open($this->factory->fromTemplate('licence_lmd'));
        $ue = $this->factory->first($doc, 'ue');
        self::assertFalse($ue->isLocked('delete'));
        $ecNid = $ue->children[0]->nid;

        self::assertTrue($doc->removeNode($ue));
        self::assertNull($doc->node($ue->nid));
        self::assertNull($doc->node($ecNid));
    }

    public function testRemoveNodeRefusesFreeParentContainingLockedDescendant(): void
    {
        $doc = $this->maquette->open($this->factory->fromTemplate('licence_lmd'));
        $ue = $this->factory->first($doc, 'ue');
        $ec = $ue->children[0];
        $ec->locked = ['delete'];

        self::assertFalse($doc->removeNode($ue));
        self::assertNotNull($doc->node($ue->nid));
        self::assertNotNull($doc->node($ec->nid));
    }

    public function testAddNodeRejectsUnknownType(): void
    {
        $doc = $this->maquette->open($this->factory->fromTemplate('licence_lmd'));

        $this->expectException(\InvalidArgumentException::class);
        $doc->addNode(null, 'type_inexistant', 'X');
    }

    public function testDuplicateNodeReassignsNids(): void
    {
        $doc = $this->maquette->open($this->factory->fromTemplate('licence_lmd'));
        $ue = $this->factory->first($doc, 'ue');
        $before = \count($doc->allNodes());

        $copy = $doc->duplicateNode($ue);

        self::assertNotSame($ue->nid, $copy->nid);
        self::assertSame($ue->typeKey, $copy->typeKey);
        self::assertCount(\count($ue->children), $copy->children);
        self::assertNotSame($ue->children[0]->nid, $copy->children[0]->nid);
        self::assertCount($before + 1 + \count($ue->children), $doc->allNodes());
    }

    public function testMoveNodeReparentsAndReorders(): void
    {
        $doc = $this->maquette->open($this->factory->fromTemplate('licence_lmd'));
        $ue = $this->factory->first($doc, 'ue');
        $otherSemestre = $doc->node($ue->parent->nid)->parent->children[1]; // semestre voisin, même année

        $doc->moveNode($ue, $otherSemestre, 0);

        self::assertSame($otherSemestre, $ue->parent);
        self::assertSame($ue, $otherSemestre->children[0]);
    }

    public function testSaveThenReopenKeepsTheTree(): void
    {
        $formation = $this->factory->fromTemplate('licence_lmd');
        $doc = $this->maquette->open($formation);
        $count = \count($doc->allNodes());
        $this->maquette->save($formation, $doc);

        $this->em->clear();
        $fresh = $this->em->find($formation::class, $formation->getId());
        $reopened = static::getContainer()->get(Maquette::class)->open($fresh);

        self::assertCount($count, $reopened->allNodes());
    }
}
