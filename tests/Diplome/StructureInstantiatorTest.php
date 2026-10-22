<?php

declare(strict_types=1);

namespace App\Tests\Diplome;

use App\Diplome\StructureInstantiator;
use App\Entity\Formation;
use App\Entity\TypeDiplome;
use App\Maquette\Maquette;
use App\Maquette\MaquetteBuilder;
use App\Maquette\Doc\MaquetteDoc;
use App\Tests\Support\DatabaseTestCase;

final class StructureInstantiatorTest extends DatabaseTestCase
{
    private function build(string $key, int $duration, bool $parcours = false): MaquetteDoc
    {
        $type = $this->em->getRepository(TypeDiplome::class)->findOneByKey($key);
        $formation = new Formation('Formation '.$key);
        $formation->setTypeDiplome($type);
        $this->em->persist($formation);
        $this->em->flush();

        $doc = static::getContainer()->get(Maquette::class)->open($formation);
        static::getContainer()->get(StructureInstantiator::class)->instantiate($doc, $type->latestVersion(), $duration, $parcours);

        return $doc;
    }

    /** @return list<\App\Maquette\Doc\TreeNode> */
    private function ofType(MaquetteDoc $doc, string $typeKey): array
    {
        return array_values(array_filter($doc->allNodes(), static fn ($n) => $n->typeKey === $typeKey));
    }

    public function testLicenceThreeYearsGivesSixSemestersAndThreeUeEach(): void
    {
        $doc = $this->build('L', 3);
        self::assertCount(3, $this->ofType($doc, 'annee'));
        self::assertCount(6, $this->ofType($doc, 'semestre'));
        self::assertCount(18, $this->ofType($doc, 'ue'));
        self::assertCount(0, $this->ofType($doc, 'ec'));
        self::assertSame(['annee', 'semestre', 'ue', 'ec'], $doc->formation->getStructure());
        self::assertFalse($doc->formation->isAvecParcours());
    }

    public function testLicenceAcceptsFourYearsButNotFive(): void
    {
        self::assertCount(4, $this->ofType($this->build('L', 4), 'annee'));
        $this->expectException(\InvalidArgumentException::class);
        $this->build('L', 5);
    }

    public function testLpNumbersSemestersFromFive(): void
    {
        $doc = $this->build('LP', 1);
        $formation = $doc->formation;
        static::getContainer()->get(Maquette::class)->saveGenerated($formation, $doc, 'Création');
        $views = static::getContainer()->get(MaquetteBuilder::class)->build($formation);
        $refs = array_map(static fn ($v) => $v->ref, $views[0]->children);
        self::assertSame(['5', '6'], $refs);
    }

    public function testM2eHasNoSemesterLevel(): void
    {
        $doc = $this->build('M2E', 2);
        self::assertCount(0, $this->ofType($doc, 'semestre'));
        self::assertCount(2, $this->ofType($doc, 'annee'));
        self::assertCount(2, $this->ofType($doc, 'bloc_formation'));
        self::assertSame(['annee', 'bloc_formation', 'ec'], $doc->formation->getStructure());
    }

    public function testObligationsAreRulesNotGeneratedNodes(): void
    {
        // LP : projet tutoré et stage du dernier semestre sont des règles, rien n'est placé ni verrouillé d'office
        $doc = $this->build('LP', 1);
        self::assertCount(0, $this->ofType($doc, 'ec'));
        self::assertSame([], array_filter($doc->allNodes(), static fn ($n) => [] !== $n->getLocked()));
    }

    public function testMultiParcoursBuildsLevelsInsideOneParcours(): void
    {
        $doc = $this->build('BUT', 6, true);
        self::assertTrue($doc->formation->isAvecParcours());
        self::assertCount(1, $doc->roots);
        self::assertSame('parcours', $doc->roots[0]->typeKey);
        self::assertCount(3, $doc->roots[0]->children);
        self::assertCount(30, $this->ofType($doc, 'ue')); // 5 UE par semestre
    }

    public function testDurationOnSemesters(): void
    {
        // le BUT compte sa durée en semestres (exactement 6)
        $doc = $this->build('BUT', 6);
        self::assertCount(3, $this->ofType($doc, 'annee'));
        self::assertCount(6, $this->ofType($doc, 'semestre'));
    }

    public function testDurationNotMultipleOfTheFactorIsRefused(): void
    {
        $type = $this->em->getRepository(TypeDiplome::class)->findOneByKey('BUT');
        $version = $type->latestVersion();
        $data = $version->config()->toArray();
        $data['structure']['duration'] = ['levelKey' => 'semestre', 'bound' => ['min' => 5, 'max' => 6]];
        $version->setConfig(\App\Diplome\Spec\VersionConfig::fromArray($data));

        $this->expectException(\InvalidArgumentException::class);
        $this->build('BUT', 5);
    }
}
