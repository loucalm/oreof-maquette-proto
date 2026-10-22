<?php

declare(strict_types=1);

namespace App\Tests\Diplome\Check;

use App\Diplome\Check\StructureViolationException;
use App\Maquette\Completion;
use App\Maquette\Doc\TreeNode;
use App\Maquette\Maquette;
use App\Tests\Support\DatabaseTestCase;
use App\Tests\Support\FormationFactory;

/** Les obligations « au moins N EC d'un type » de la LP passées au garde, sur le vrai chemin d'enregistrement. */
final class LpObligationsGuardTest extends DatabaseTestCase
{
    private FormationFactory $factory;
    private Maquette $maquette;

    protected function setUp(): void
    {
        parent::setUp();
        $this->factory = FormationFactory::create(static::getContainer());
        $this->maquette = static::getContainer()->get(Maquette::class);
    }

    public function testLpCanGrowFromOneToTwoYears(): void
    {
        $formation = $this->factory->fromType('LP', 1);
        $doc = $this->maquette->open($formation);

        // une année de plus : le dernier semestre change, l'obligation non remplie reste le même écart
        $annee = $doc->addNode(null, 'annee');
        $doc->addNode($annee->nid, 'semestre');
        $doc->addNode($annee->nid, 'semestre');
        $this->maquette->save($formation, $doc, 'Ajout d’une année');

        $doc = $this->maquette->open($formation);
        self::assertCount(2, array_filter($doc->pedagogicalRoots(), static fn (TreeNode $n) => 'annee' === $n->typeKey));
        $stage = array_values(array_filter(static::getContainer()->get(Completion::class)->structureViolations($doc), static fn ($v) => 'rule:lp-stage:min' === $v->ruleKey));
        self::assertCount(1, $stage);
        self::assertSame('', $stage[0]->scopeNid, 'portée « dernier » : le cursus (racine en mono-parcours)');
    }

    public function testRemovingTheOnlyRequiredStageIsRefused(): void
    {
        $formation = $this->factory->fromType('LP', 1);
        $doc = $this->maquette->open($formation);
        $semestres = array_values(array_filter($doc->allNodes(), static fn (TreeNode $n) => 'semestre' === $n->typeKey));
        $ue = end($semestres)->getChildren()[0];
        $stage = $doc->addNode($ue->nid, 'ec', 'Stage');
        $stage->setAttribute('natureElement', 'stage');
        $this->maquette->save($formation, $doc, 'Ajout du stage');

        $doc = $this->maquette->open($formation);
        $doc->removeNode($doc->node($stage->nid));
        $this->expectException(StructureViolationException::class);
        $this->maquette->save($formation, $doc, 'Suppression du stage');
    }
}
