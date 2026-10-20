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
        // l'élément imposé d'une Licence professionnelle est verrouillé contre la suppression
        $doc = $this->maquette->open($this->factory->fromType('LP', 1));
        $imposed = current(array_filter($doc->allNodes(), static fn ($n) => null !== $n->getAttribute('_imposed')));
        self::assertTrue($imposed->isLocked('delete'));

        self::assertFalse($doc->removeNode($imposed));
        self::assertNotNull($doc->node($imposed->nid));
    }

    public function testRemoveNodeForceRemovesLockedNodeAndItsSubtree(): void
    {
        $doc = $this->maquette->open($this->factory->fromType('L', 3, withLeaves: true));
        $annee = $this->factory->first($doc, 'annee');
        $descendantNid = $annee->children[0]->nid;

        self::assertTrue($doc->removeNode($annee, true));
        self::assertNull($doc->node($annee->nid));
        self::assertNull($doc->node($descendantNid));
    }

    public function testRemoveNodeRemovesFreeNodeWithDescendants(): void
    {
        $doc = $this->maquette->open($this->factory->fromType('L', 3, withLeaves: true));
        $ue = $this->factory->first($doc, 'ue');
        self::assertFalse($ue->isLocked('delete'));
        $ecNid = $ue->children[0]->nid;

        self::assertTrue($doc->removeNode($ue));
        self::assertNull($doc->node($ue->nid));
        self::assertNull($doc->node($ecNid));
    }

    public function testRemoveNodeRefusesFreeParentContainingLockedDescendant(): void
    {
        $doc = $this->maquette->open($this->factory->fromType('L', 3, withLeaves: true));
        $ue = $this->factory->first($doc, 'ue');
        $ec = $ue->children[0];
        $ec->locked = ['delete'];

        self::assertFalse($doc->removeNode($ue));
        self::assertNotNull($doc->node($ue->nid));
        self::assertNotNull($doc->node($ec->nid));
    }

    public function testAddNodeRejectsUnknownType(): void
    {
        $doc = $this->maquette->open($this->factory->fromType('L', 3, withLeaves: true));

        $this->expectException(\InvalidArgumentException::class);
        $doc->addNode(null, 'type_inexistant', 'X');
    }

    public function testDuplicateNodeReassignsNids(): void
    {
        $doc = $this->maquette->open($this->factory->fromType('L', 3, withLeaves: true));
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
        $doc = $this->maquette->open($this->factory->fromType('L', 3, withLeaves: true));
        $ue = $this->factory->first($doc, 'ue');
        $otherSemestre = $doc->node($ue->parent->nid)->parent->children[1]; // semestre voisin, même année

        $doc->moveNode($ue, $otherSemestre, 0);

        self::assertSame($otherSemestre, $ue->parent);
        self::assertSame($ue, $otherSemestre->children[0]);
    }

    public function testSaveThenReopenKeepsTheTree(): void
    {
        $formation = $this->factory->fromType('L', 3, withLeaves: true);
        $doc = $this->maquette->open($formation);
        $count = \count($doc->allNodes());
        $this->maquette->save($formation, $doc);

        $this->em->clear();
        $fresh = $this->em->find($formation::class, $formation->getId());
        $reopened = static::getContainer()->get(Maquette::class)->open($fresh);

        self::assertCount($count, $reopened->allNodes());
    }
}
