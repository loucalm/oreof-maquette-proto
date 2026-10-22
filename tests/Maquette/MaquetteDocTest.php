<?php

declare(strict_types=1);

namespace App\Tests\Maquette;

use App\Diplome\Check\StructureChecker;
use App\Entity\Formation;
use App\Maquette\Doc\TreeNode;
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
        $doc = $this->maquette->open($this->factory->fromType('L', 3, withLeaves: true));
        $ue = $this->factory->first($doc, 'ue');
        $ue->locked = ['delete'];

        self::assertFalse($doc->removeNode($ue));
        self::assertNotNull($doc->node($ue->nid));
    }

    public function testLegacyImposedNodesStayOrdinary(): void
    {
        // une maquette enregistrée avant la suppression des éléments imposés : nœud marqué `_imposed` et verrouillé
        $source = $this->factory->fromType('L', 3, withLeaves: true);
        $arbre = $source->getArbre();
        $arbre[0]['attributes']['_imposed'] = 'lp-projet-stage';
        $arbre[0]['locked'] = ['delete'];
        $nid = $arbre[0]['nid'];
        $legacy = (new Formation('Formation ancienne'))
            ->setTypeDiplome($source->getTypeDiplome())
            ->setAnneeUniversitaire($source->getAnneeUniversitaire())
            ->setStructure($source->getStructure())
            ->setArbre($arbre);
        $this->em->persist($legacy);
        $this->em->flush();

        $reopened = $this->maquette->open($legacy);
        $node = $reopened->node($nid);
        self::assertNotNull($node);
        self::assertNull($node->getAttribute('_imposed'));
        self::assertSame([], $node->getLocked());

        $violations = static::getContainer()->get(StructureChecker::class)->check($reopened, $legacy->getTypeDiplome()->latestVersion()->config());
        self::assertSame([], array_filter($violations, static fn ($v) => str_starts_with($v->ruleKey, 'imposed:')));

        self::assertTrue($reopened->removeNode($node));
        self::assertNull($reopened->node($nid));
    }

    public function testLockedNodeWithoutLegacyMarkerKeepsItsLocksThroughJson(): void
    {
        $raw = (new TreeNode(nid: 'n1', typeKey: 'ue', locked: ['delete', 'move']))->toArray();
        $copy = TreeNode::fromArray(json_decode((string) json_encode($raw), true));

        self::assertSame(['delete', 'move'], $copy->getLocked());
        self::assertTrue($copy->isLocked('delete'));
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
