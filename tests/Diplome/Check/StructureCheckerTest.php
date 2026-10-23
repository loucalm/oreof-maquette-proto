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
        static::getContainer()->get(StructureInstantiator::class)->instantiate($doc, $type->latestVersion(), $duration, $parcours);
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

    public function testLpObligationsAreBlockingUntilFilled(): void
    {
        $doc = $this->build('LP', 1);
        $semestres = $this->ofType($doc, 'semestre');
        $last = end($semestres);

        $found = $this->withKey($this->checker->check($doc, $this->config), 'rule:lp-stage:min');
        self::assertCount(1, $found);
        self::assertTrue($found[0]->blocking);
        self::assertSame('', $found[0]->scopeNid, 'portée « dernier » : signalée sous le cursus (racine en mono-parcours)');

        $doc->addNode($last->getChildren()[0]->nid, 'ec', 'Stage')->setAttribute('natureElement', 'stage');
        self::assertSame([], $this->withKey($this->checker->check($doc, $this->config), 'rule:lp-stage:min'));
        self::assertCount(1, $this->withKey($this->checker->check($doc, $this->config), 'rule:lp-projet-tutore:min'));
    }

    public function testDefaultNatureIsTheFirstDeclared(): void
    {
        $doc = $this->build('BUT', 6);
        $ue = $this->ofType($doc, 'ue')[0];
        $ec = $doc->addNode($ue->nid, 'ec', 'Sans nature');

        $context = static::getContainer()->get(\App\Diplome\Check\StructureContextBuilder::class)->contextFor($doc, $ue, $this->config);
        $row = array_values(array_filter($context['children'], static fn (array $r) => $r['nid'] === $ec->getId()))[0];

        self::assertSame($this->config->structure->natureKeys()[0], $row['nature']);
        self::assertSame($row['nature'], $this->config->natureOf($ec));
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

    public function testUnknownTemplateIsReportedNonBlocking(): void
    {
        $doc = $this->build('L', 3);
        $array = $this->config->toArray();
        $array['structure']['rules'][] = ['uid' => 'fantome', 'template' => 'gabarit_disparu', 'params' => [], 'scopeLevel' => 'ue', 'severity' => 'error'];

        $found = $this->withKey($this->checker->check($doc, VersionConfig::fromArray($array)), 'rule:fantome:unknown');
        self::assertCount(1, $found);
        self::assertFalse($found[0]->blocking);
        self::assertSame('Règle inconnue : gabarit_disparu', $found[0]->message);
    }

    /** Portail d'une année + sous-parcours de $years années chacun (ramification), dans une Licence (durée 3 à 4). */
    private function ramified(int $years, int $subs = 2): MaquetteDoc
    {
        $doc = $this->build('L', 3, true);
        $portail = $doc->parcoursNodes()[0];
        foreach (\array_slice($portail->getChildren(), 1) as $annee) {
            $doc->removeNode($annee, true);
        }
        $portail->setAttribute('periodeDebut', 1)->setAttribute('periodeFin', 1);
        for ($i = 0; $i < $subs; ++$i) {
            $sub = $doc->addNode(null, 'parcours', 'Sous-parcours '.($i + 1));
            $sub->setAttribute('periodeDebut', 2)->setAttribute('periodeFin', 1 + $years)->setParcoursParent($portail);
            for ($y = 0; $y < $years; ++$y) {
                $doc->addNode($sub->nid, 'annee');
            }
        }

        return $doc;
    }

    public function testDurationIsMeasuredPerCursusInARamification(): void
    {
        // portail 1 an + sous-parcours de 2 ans : 3 ans de cursus, aucun écart de durée
        $doc = $this->ramified(2);

        self::assertSame([], $this->withKey($this->checker->check($doc, $this->config), 'duration:min'));
        self::assertSame([], $this->withKey($this->checker->check($doc, $this->config), 'duration:max'));
    }

    public function testRamificationTooShortGetsDurationMinOnTheLeafParcours(): void
    {
        // portail 1 an + sous-parcours d'1 an : 2 ans de cursus, sous le minimum de 3
        $doc = $this->ramified(1, 1);

        $found = $this->withKey($this->checker->check($doc, $this->config), 'duration:min');
        self::assertCount(1, $found);
        self::assertSame($doc->parcoursNodes()[1]->nid, $found[0]->scopeNid);
        self::assertSame(2, $found[0]->actual);
    }

    public function testDurationCountsDescendants(): void
    {
        // le BUT compte sa durée en semestres (exactement 6)
        $doc = $this->build('BUT', 6);
        $config = $this->config;

        $found = array_filter($this->checker->check($doc, $config), static fn ($v) => str_starts_with($v->ruleKey, 'duration:'));
        self::assertSame([], array_values($found));

        $semestres = $this->ofType($doc, 'semestre');
        $doc->removeNode(end($semestres), true);
        $found = $this->withKey($this->checker->check($doc, $config), 'duration:min');
        self::assertCount(1, $found);
        self::assertSame(5, $found[0]->actual);
    }

    public function testRuleOnLastSemesterOnly(): void
    {
        $doc = $this->build('LP', 1);
        $array = $this->config->toArray();
        $array['structure']['rules'][] = ['uid' => 'stage-s6', 'template' => 'min_element_type', 'params' => ['nature' => 'stage', 'min' => 1], 'scopeLevel' => 'semestre', 'scopePosition' => 'last', 'severity' => 'error'];
        $config = VersionConfig::fromArray($array);

        $semestres = $this->ofType($doc, 'semestre');
        $last = end($semestres);
        $ue = $doc->addNode($last->nid, 'ue');
        $ec = $doc->addNode($ue->nid, 'ec', 'Stage');
        $ec->setAttribute('natureElement', 'stage');

        self::assertSame([], $this->withKey($this->checker->check($doc, $config), 'rule:stage-s6:min'));

        $doc->removeNode($ec, true);
        $found = $this->withKey($this->checker->check($doc, $config), 'rule:stage-s6:min');
        self::assertCount(1, $found);
        self::assertSame('', $found[0]->scopeNid, 'portée « dernier » : le cursus, pas le semestre');
        self::assertTrue($found[0]->blocking);
    }

    public function testRuleOnDisplayedNumber(): void
    {
        $doc = $this->build('LP', 1);
        $array = $this->config->toArray();
        $array['structure']['rules'][] = ['uid' => 'stage-n', 'template' => 'min_element_type', 'params' => ['nature' => 'stage', 'min' => 1], 'scopeLevel' => 'semestre', 'scopePosition' => '5', 'severity' => 'error'];

        $found = $this->withKey($this->checker->check($doc, VersionConfig::fromArray($array)), 'rule:stage-n:min');
        self::assertCount(1, $found);
        self::assertSame($this->ofType($doc, 'semestre')[0]->nid, $found[0]->scopeNid);
    }
}
