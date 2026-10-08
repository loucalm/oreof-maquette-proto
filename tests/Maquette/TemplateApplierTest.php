<?php

declare(strict_types=1);

namespace App\Tests\Maquette;

use App\Maquette\Maquette;
use App\Maquette\TemplateApplier;
use App\Repository\StructureTemplateRepository;
use App\Tests\Support\DatabaseTestCase;
use App\Tests\Support\FormationFactory;

final class TemplateApplierTest extends DatabaseTestCase
{
    private FormationFactory $factory;
    private Maquette $maquette;

    protected function setUp(): void
    {
        parent::setUp();
        $this->factory = FormationFactory::create(static::getContainer());
        $this->maquette = static::getContainer()->get(Maquette::class);
    }

    public function testLicenceTemplateBuildsTheImposedStructure(): void
    {
        $formation = $this->factory->fromTemplate('licence_lmd');
        $doc = $this->maquette->open($formation);

        self::assertFalse($formation->isAvecParcours());
        self::assertSame(['annee', 'semestre', 'ue', 'ec'], $formation->getStructure());
        self::assertSame('Année', $formation->getCalendarUnit());
        self::assertSame(3, $formation->getCalendarSpan());

        $annees = array_values(array_filter($doc->allNodes(), static fn ($n) => 'annee' === $n->typeKey));
        $semestres = array_values(array_filter($doc->allNodes(), static fn ($n) => 'semestre' === $n->typeKey));
        self::assertCount(3, $annees);
        self::assertCount(6, $semestres);
        foreach ([...$annees, ...$semestres] as $imposed) {
            self::assertTrue($imposed->isLocked('delete'), $imposed->label.' devrait être imposé');
            self::assertTrue($imposed->isLocked('add'));
        }
    }

    public function testMasterTemplateIsMultiParcoursWithImposedParcours(): void
    {
        $formation = $this->factory->fromTemplate('master_parcours');
        $doc = $this->maquette->open($formation);

        self::assertTrue($formation->isAvecParcours());
        self::assertNotSame([], $doc->parcoursNodes());
        foreach ($doc->parcoursNodes() as $parcours) {
            self::assertTrue($parcours->isLocked('delete'));
        }
    }

    public function testApplyReplacesPreviousContent(): void
    {
        $formation = $this->factory->fromTemplate('licence_lmd');
        $doc = $this->maquette->open($formation);
        $doc->addNode(null, 'annee', 'Année en plus');
        $template = static::getContainer()->get(StructureTemplateRepository::class)->findOneByKey('licence_lmd');

        static::getContainer()->get(TemplateApplier::class)->apply($doc, $template);

        self::assertCount(3, array_filter($doc->allNodes(), static fn ($n) => 'annee' === $n->typeKey));
    }

    public function testChainFromTreeTakesFirstTypePerDepth(): void
    {
        $applier = static::getContainer()->get(TemplateApplier::class);
        $tree = [
            ['type' => 'annee', 'children' => [
                ['type' => 'semestre', 'children' => [['type' => 'ue', 'children' => [['type' => 'ec']]]]],
            ]],
        ];

        self::assertSame(['annee', 'semestre', 'ue', 'ec'], $applier->chainFromTree($tree));
    }
}
