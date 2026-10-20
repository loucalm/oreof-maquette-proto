<?php

declare(strict_types=1);

namespace App\Tests\Maquette;

use App\Maquette\Completion;
use App\Maquette\MaquetteBuilder;
use App\Maquette\Maquette;
use App\Tests\Support\DatabaseTestCase;
use App\Tests\Support\FormationFactory;

final class CompletionTest extends DatabaseTestCase
{
    private FormationFactory $factory;
    private Maquette $maquette;
    private Completion $completion;

    protected function setUp(): void
    {
        parent::setUp();
        $this->factory = FormationFactory::create(static::getContainer());
        $this->maquette = static::getContainer()->get(Maquette::class);
        $this->completion = static::getContainer()->get(Completion::class);
    }

    public function testTemporalNodeDoesNotRequireALabel(): void
    {
        $doc = $this->maquette->open($this->factory->fromTemplate('licence_lmd'));
        $semestre = $this->factory->first($doc, 'semestre');

        $semestre->setLabel('');
        $empty = $this->completion->node($semestre);
        $semestre->setLabel('Semestre 1');
        $filled = $this->completion->node($semestre);

        self::assertSame($filled, $empty, 'le libellé d’un type temporel est calculé : il ne compte pas');
    }

    public function testStructuralNodeRequiresALabel(): void
    {
        $doc = $this->maquette->open($this->factory->fromTemplate('licence_lmd'));
        $ue = $this->factory->first($doc, 'ue');

        $ue->setLabel('');
        $empty = $this->completion->node($ue);
        $ue->setLabel('UE 1');
        $filled = $this->completion->node($ue);

        self::assertSame($empty['req'], $filled['req']);
        self::assertSame($empty['filled'] + 1, $filled['filled']);
        self::assertGreaterThanOrEqual(1, $filled['req']);
    }

    public function testCollectIssuesNeverReportsAMissingLabelOnTemporalNodes(): void
    {
        $formation = $this->factory->fromTemplate('licence_lmd');
        $doc = $this->maquette->open($formation);
        foreach ($doc->allNodes() as $node) {
            if ($node->getType()->isTemporel()) {
                $node->setLabel('');
            }
        }

        $builder = static::getContainer()->get(MaquetteBuilder::class);
        $issues = $builder->collectIssues($builder->build($formation));
        self::assertNotEmpty($issues, 'les EC sans code doivent toujours remonter');
        foreach ($issues as $issue) {
            if ($issue['node']->getType()->isTemporel()) {
                self::assertNotContains('libellé', $issue['missing']);
            }
        }
    }

    public function testDocStatsProgressIsAPercentage(): void
    {
        $doc = $this->maquette->open($this->factory->fromTemplate('licence_lmd'));

        $stats = $this->completion->docStats($doc);

        self::assertGreaterThanOrEqual(0, $stats['progress']);
        self::assertLessThanOrEqual(100, $stats['progress']);
        self::assertGreaterThan(0, $stats['req']);
        self::assertLessThanOrEqual($stats['req'], $stats['filled']);
    }

    public function testInvalidMcccCountsAsMissing(): void
    {
        $doc = $this->maquette->open($this->factory->fromTemplate('licence_lmd'));
        $ec = $this->factory->first($doc, 'ec');
        // profil CCI Licence : au moins 3 épreuves, somme = 100
        $ec->setAttribute('mccc', ['type' => 'CCI', 'evaluations' => [['type' => 'ecrit', 'weight' => 100]]]);

        $keys = array_column($this->completion->missing($ec), 'key');

        self::assertContains('mccc', $keys, 'une MCCC non conforme ne compte pas comme remplie');
    }

    public function testValidMcccIsNotMissing(): void
    {
        $doc = $this->maquette->open($this->factory->fromTemplate('licence_lmd'));
        $ec = $this->factory->first($doc, 'ec');
        $ec->setAttribute('mccc', ['type' => 'CCI', 'evaluations' => [
            ['type' => 'ecrit', 'weight' => 40, 'duree' => 60],
            ['type' => 'oral', 'weight' => 30, 'duree' => 30],
            ['type' => 'ecrit', 'weight' => 30, 'duree' => 60],
        ]]);

        self::assertNotContains('mccc', array_column($this->completion->missing($ec), 'key'));
    }

    public function testStructureViolationsLowerProgressAndAreCounted(): void
    {
        $type = $this->em->getRepository(\App\Entity\TypeDiplome::class)->findOneByKey('L');
        $formation = new \App\Entity\Formation('Licence');
        $formation->setTypeDiplome($type);
        $this->em->persist($formation);
        $this->em->flush();
        $doc = $this->maquette->open($formation);
        (new \App\Diplome\StructureInstantiator())->instantiate($doc, $type->latestVersion(), 3, false);

        $before = $this->completion->docStats($doc);
        $beforeCount = \count($this->completion->structureViolations($doc));
        $s1 = $this->factory->first($doc, 'semestre');
        for ($i = 0; $i < 4; ++$i) {
            $doc->addNode($s1->nid, 'ue');
        }
        $after = $this->completion->docStats($doc);
        $violations = $this->completion->structureViolations($doc);

        self::assertSame(1, $after['structure']['blocking']);
        self::assertSame(\count($violations) - 1, $after['structure']['incomplete']);
        // formule documentée : +1 `req` non rempli par violation ; les 4 UE ajoutées apportent aussi leurs champs requis
        $newUes = array_map(fn ($ue) => $this->completion->node($ue), array_slice($s1->getChildren(), 3));
        $addedReq = array_sum(array_column($newUes, 'req'));
        $addedFilled = array_sum(array_column($newUes, 'filled'));
        self::assertSame(
            $before['req'] + $addedReq + (\count($violations) - $beforeCount),
            $after['req'],
        );
        self::assertLessThanOrEqual($before['progress'], $after['progress']);
        self::assertSame($before['filled'] + $addedFilled, $after['filled']);
    }
}
