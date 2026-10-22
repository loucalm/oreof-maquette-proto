<?php

declare(strict_types=1);

namespace App\Tests\Diplome\Check;

use App\Diplome\Check\LevelLockCheck;
use App\Diplome\Spec\LevelSpec;
use App\Diplome\Spec\StructureSpec;
use App\Diplome\StructureInstantiator;
use App\Entity\Formation;
use App\Entity\TypeDiplome;
use App\Maquette\Doc\MaquetteDoc;
use App\Maquette\Doc\TreeNode;
use App\Maquette\Maquette;
use App\Tests\Support\DatabaseTestCase;

final class LevelLockCheckTest extends DatabaseTestCase
{
    private LevelLockCheck $check;

    protected function setUp(): void
    {
        parent::setUp();
        $this->check = static::getContainer()->get(LevelLockCheck::class);
    }

    private function licence(): MaquetteDoc
    {
        $type = $this->em->getRepository(TypeDiplome::class)->findOneByKey('L');
        $formation = new Formation('Licence verrouillée');
        $formation->setTypeDiplome($type);
        $this->em->persist($formation);
        $this->em->flush();
        $doc = static::getContainer()->get(Maquette::class)->open($formation);
        static::getContainer()->get(StructureInstantiator::class)->instantiate($doc, $type->latestVersion(), 3, false);

        return $doc;
    }

    private static function spec(bool $semestreLocked = true): StructureSpec
    {
        return new StructureSpec([new LevelSpec('annee'), new LevelSpec('semestre', null, null, null, 1, $semestreLocked), new LevelSpec('ue')]);
    }

    /** @return list<TreeNode> */
    private function semestres(MaquetteDoc $doc, int $annee = 0): array
    {
        return $doc->pedagogicalRoots()[$annee]->getChildren();
    }

    /** Même structure, mêmes nids : une seconde instanciation sert d'état « avant » à celle que le test modifie. */
    private function snapshot(MaquetteDoc $after): MaquetteDoc
    {
        return $this->licence();
    }

    public function testReorderIsRefused(): void
    {
        $after = $this->licence();
        $before = $this->snapshot($after);
        $annee = $after->pedagogicalRoots()[0];
        $after->moveNode($this->semestres($after)[1], $annee, 0);

        $v = $this->check->check($before, $after, self::spec());
        self::assertCount(1, $v);
        self::assertSame('lock:semestre', $v[0]->ruleKey);
        self::assertSame($annee->nid, $v[0]->scopeNid);
        self::assertTrue($v[0]->blocking);
        self::assertNull($v[0]->actual);
        self::assertNull($v[0]->expected);
    }

    public function testAddIsRefused(): void
    {
        $after = $this->licence();
        $before = $this->snapshot($after);
        $annee = $after->pedagogicalRoots()[0];
        $after->addNode($annee->nid, 'semestre');

        $v = $this->check->check($before, $after, self::spec());
        self::assertSame(['lock:semestre'], array_map(static fn ($x) => $x->ruleKey, $v));
        self::assertSame($annee->nid, $v[0]->scopeNid);
    }

    public function testDeleteIsRefused(): void
    {
        $after = $this->licence();
        $before = $this->snapshot($after);
        $after->removeNode($this->semestres($after)[1], true);

        $v = $this->check->check($before, $after, self::spec());
        self::assertCount(1, $v);
        self::assertSame('lock:semestre', $v[0]->ruleKey);
    }

    public function testRecreatedNodeIsRefused(): void
    {
        $after = $this->licence();
        $before = $this->snapshot($after);
        $annee = $after->pedagogicalRoots()[0];
        $after->removeNode($this->semestres($after)[1], true);
        $after->addNode($annee->nid, 'semestre');

        $v = $this->check->check($before, $after, self::spec());
        self::assertCount(1, $v);
        self::assertSame($annee->nid, $v[0]->scopeNid);
    }

    public function testRenameIsAllowed(): void
    {
        $after = $this->licence();
        $before = $this->snapshot($after);
        $semestre = $this->semestres($after)[0];
        $semestre->label = 'Renommé';
        $semestre->attributes['heures'] = 12;

        self::assertSame([], $this->check->check($before, $after, self::spec()));
    }

    public function testUnlockedLevelIgnored(): void
    {
        $after = $this->licence();
        $before = $this->snapshot($after);
        $after->addNode($after->pedagogicalRoots()[0]->nid, 'semestre');

        self::assertSame([], $this->check->check($before, $after, self::spec(false)));
    }
}
