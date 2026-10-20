<?php

declare(strict_types=1);

namespace App\Tests\Diplome\Check;

use App\Diplome\Check\StructureChecker;
use App\Diplome\Check\StructureViolation;
use App\Diplome\Spec\VersionConfig;
use App\Diplome\StructureInstantiator;
use App\Entity\Formation;
use App\Entity\TypeDiplome;
use App\Maquette\Doc\MaquetteDoc;
use App\Maquette\Doc\TreeNode;
use App\Maquette\Maquette;
use App\Tests\Support\DatabaseTestCase;

final class StructureCheckerTest extends DatabaseTestCase
{
    private StructureChecker $checker;
    private VersionConfig $config;

    protected function setUp(): void
    {
        parent::setUp();
        $this->checker = static::getContainer()->get(StructureChecker::class);
    }

    private function build(string $key, int $duration, bool $parcours = false): MaquetteDoc
    {
        $type = $this->em->getRepository(TypeDiplome::class)->findOneByKey($key);
        $formation = new Formation('Formation '.$key);
        $formation->setTypeDiplome($type);
        $this->em->persist($formation);
        $this->em->flush();

        $doc = static::getContainer()->get(Maquette::class)->open($formation);
        (new StructureInstantiator())->instantiate($doc, $type->latestVersion(), $duration, $parcours);
        $this->config = $type->latestVersion()->config();

        return $doc;
    }

    /** @return list<TreeNode> */
    private function ofType(MaquetteDoc $doc, string $typeKey): array
    {
        return array_values(array_filter($doc->allNodes(), static fn ($n) => $n->typeKey === $typeKey));
    }

    /** @return list<StructureViolation> */
    private function withKey(array $violations, string $key): array
    {
        return array_values(array_filter($violations, static fn (StructureViolation $v) => $v->ruleKey === $key));
    }

    public function testFreshLicenceHasNoBlockingViolation(): void
    {
        $doc = $this->build('L', 3);
        $violations = $this->checker->check($doc, $this->config);
        self::assertSame([], array_filter($violations, static fn ($v) => $v->blocking));
    }

    public function testSeventhUeInSemesterIsBlocking(): void
    {
        $doc = $this->build('L', 3);
        $s1 = $this->ofType($doc, 'semestre')[0];
        for ($i = 0; $i < 4; ++$i) {
            $doc->addNode($s1->nid, 'ue');
        }
        $found = $this->withKey($this->checker->check($doc, $this->config), 'level:ue:count:max');
        self::assertCount(1, $found);
        self::assertTrue($found[0]->blocking);
        self::assertSame($s1->nid, $found[0]->scopeNid);
        self::assertSame(7, $found[0]->actual);
        self::assertSame(6, $found[0]->expected);
    }

    public function testTwoUeIsOnlyIncomplete(): void
    {
        $doc = $this->build('L', 3);
        $s1 = $this->ofType($doc, 'semestre')[0];
        $doc->removeNode($s1->getChildren()[0], true);
        $v = $this->checker->check($doc, $this->config);
        $found = $this->withKey($v, 'level:ue:count:min');
        self::assertCount(1, $found);
        self::assertFalse($found[0]->blocking);
        self::assertSame(2, $found[0]->actual);
        self::assertSame([], $this->withKey($v, 'level:ue:count:max'));
    }

    public function testSemesterEctsSumMustBeThirty(): void
    {
        $doc = $this->build('L', 3);
        $s1 = $this->ofType($doc, 'semestre')[0];
        foreach ($s1->getChildren() as $i => $ue) {
            $ue->setAttribute('ects', [10, 10, 12][$i]);
        }
        $found = $this->withKey($this->checker->check($doc, $this->config), 'level:semestre:ectsSum:max');
        self::assertCount(1, $found);
        self::assertSame($s1->nid, $found[0]->scopeNid);
        self::assertSame(32, $found[0]->actual);
        self::assertSame(30, $found[0]->expected);
        self::assertTrue($found[0]->blocking);
    }

    public function testDurationOutOfBoundsIsReported(): void
    {
        $doc = $this->build('L', 3);
        $doc->addNode(null, 'annee');
        $doc->addNode(null, 'annee');
        $found = $this->withKey($this->checker->check($doc, $this->config), 'duration:max');
        self::assertCount(1, $found);
        self::assertSame('', $found[0]->scopeNid);
        self::assertSame(5, $found[0]->actual);
    }

    public function testMissingImposedElementIsBlocking(): void
    {
        $doc = $this->build('LP', 1);
        self::assertSame([], $this->withKey($this->checker->check($doc, $this->config), 'imposed:lp-projet-stage'));

        $semestres = $this->ofType($doc, 'semestre');
        $last = end($semestres);
        $imposed = array_values(array_filter($last->getChildren(), static fn ($n) => 'lp-projet-stage' === $n->getAttribute('_imposed')));
        $doc->removeNode($imposed[0], true);

        $found = $this->withKey($this->checker->check($doc, $this->config), 'imposed:lp-projet-stage');
        self::assertCount(1, $found);
        self::assertTrue($found[0]->blocking);
        self::assertSame($last->nid, $found[0]->scopeNid);
    }

    public function testBlocChoiceWithUnevenChildrenFailsRule(): void
    {
        $doc = $this->build('L', 3);
        $ue = $this->ofType($doc, 'ue')[0];
        $bloc = $doc->addNode($ue->nid, 'bloc_choix');
        $doc->addNode($bloc->nid, 'ec')->setAttribute('ects', 3);
        $doc->addNode($bloc->nid, 'ec')->setAttribute('ects', 4);

        $array = $this->config->toArray();
        $array['structure']['rules'][] = ['uid' => 'choix-egaux', 'template' => 'same_child_value', 'params' => ['attribute' => 'ects'], 'scopeLevel' => 'bloc_choix', 'severity' => 'error'];
        $config = VersionConfig::fromArray($array);

        $found = $this->withKey($this->checker->check($doc, $config), 'rule:choix-egaux');
        self::assertCount(1, $found);
        self::assertSame($bloc->nid, $found[0]->scopeNid);
        self::assertTrue($found[0]->blocking);

        $array['structure']['rules'][0]['severity'] = 'warning';
        $warn = $this->withKey($this->checker->check($doc, VersionConfig::fromArray($array)), 'rule:choix-egaux');
        self::assertFalse($warn[0]->blocking);
    }

    public function testCountOverrideReplacesLevelBoundOnItsHostOnly(): void
    {
        $doc = $this->build('LP', 1);
        $array = $this->config->toArray();
        $array['structure']['imposed'][0]['countOverride'] = ['min' => 1, 'max' => 2];
        $config = VersionConfig::fromArray($array);

        $semestres = $this->ofType($doc, 'semestre');
        $last = end($semestres);
        while (\count($last->getChildren()) < 4) {
            $doc->addNode($last->nid, 'ue');
        }
        $keys = array_map(static fn ($v) => $v->ruleKey.'@'.$v->scopeNid, $this->checker->check($doc, $config));
        self::assertContains('level:ue:count:max@'.$last->nid, $keys);
        self::assertNotContains('level:ue:count:min@'.$last->nid, $keys);
    }
}
