<?php

declare(strict_types=1);

namespace App\Tests\Diplome\Check;

use App\Diplome\Check\StructureChecker;
use App\Diplome\Check\StructureViolation;
use App\Diplome\ImposedPropagator;
use App\Diplome\StructureInstantiator;
use App\Entity\Formation;
use App\Entity\TypeDiplome;
use App\Maquette\Doc\MaquetteDoc;
use App\Maquette\Doc\TreeNode;
use App\Maquette\Maquette;
use App\Tests\Support\DatabaseTestCase;

/**
 * Un élément imposé est « présent » s'il existe dans sa portée (parcours / cursus, racine en mono) :
 * sa position ne sert qu'à le placer. Étendre normalement la maquette ne doit donc pas être refusé.
 */
final class ImposedPresenceTest extends DatabaseTestCase
{
    private Maquette $maquette;

    protected function setUp(): void
    {
        parent::setUp();
        $this->maquette = static::getContainer()->get(Maquette::class);
    }

    /** @return array{0: Formation, 1: MaquetteDoc} formation enregistrée (passée par le garde) et sa maquette */
    private function create(string $key, int $duration, bool $parcours = false): array
    {
        $type = $this->em->getRepository(TypeDiplome::class)->findOneByKey($key);
        $formation = (new Formation('Formation '.$key))->setTypeDiplome($type);
        $this->em->persist($formation);
        $this->em->flush();
        $doc = $this->maquette->open($formation);
        static::getContainer()->get(StructureInstantiator::class)->instantiate($doc, $type->latestVersion(), $duration, $parcours);
        $this->maquette->save($formation, $doc, 'Création');

        return [$formation, $this->maquette->open($formation)];
    }

    /** @return list<TreeNode> */
    private function ofType(MaquetteDoc $doc, string $typeKey): array
    {
        return array_values(array_filter($doc->allNodes(), static fn (TreeNode $n) => $n->typeKey === $typeKey));
    }

    /** @return list<TreeNode> */
    private function imposed(MaquetteDoc $doc, string $uid): array
    {
        return array_values(array_filter($doc->allNodes(), static fn (TreeNode $n) => $uid === $n->getAttribute('_imposed')));
    }

    /** @return list<StructureViolation> */
    private function imposedViolations(Formation $formation, MaquetteDoc $doc, string $uid): array
    {
        $config = $formation->getTypeDiplome()->latestVersion()->config();

        return array_values(array_filter(
            static::getContainer()->get(StructureChecker::class)->check($doc, $config),
            static fn (StructureViolation $v) => 'imposed:'.$uid === $v->ruleKey,
        ));
    }

    public function testButAddingAnUeAtTheEndOfS6IsAccepted(): void
    {
        [$formation, $doc] = $this->create('BUT', 3);
        $semestres = $this->ofType($doc, 'semestre');
        $doc->addNode(end($semestres)->nid, 'ue', 'UE de fin');

        $this->maquette->save($formation, $doc, 'Ajout UE'); // ne lève pas

        self::assertSame([], $this->imposedViolations($formation, $doc, 'but-stage'));
        // la propagation ne crée pas de doublon : l'élément est déjà présent dans la portée
        self::assertSame(0, static::getContainer()->get(ImposedPropagator::class)->propagate($formation, $formation->getTypeDiplome()->latestVersion()->config()));
        self::assertCount(1, $this->imposed($this->maquette->open($formation), 'but-stage'));
    }

    public function testLpCanGrowFromOneToTwoYears(): void
    {
        [$formation, $doc] = $this->create('LP', 1);

        $annee = $doc->addNode(null, 'annee');
        $this->maquette->save($formation, $doc, 'Année 2');
        $doc = $this->maquette->open($formation);
        $doc->addNode($annee->nid, 'semestre');
        $this->maquette->save($formation, $doc, 'S7');
        $doc = $this->maquette->open($formation);
        $doc->addNode($annee->nid, 'semestre');
        $this->maquette->save($formation, $doc, 'S8');

        $doc = $this->maquette->open($formation);
        self::assertCount(2, $this->ofType($doc, 'annee'));
        self::assertCount(4, $this->ofType($doc, 'semestre'));
        self::assertCount(1, $this->imposed($doc, 'lp-projet-stage'));
    }

    public function testForcedRemovalOfTheImposedElementIsStillReported(): void
    {
        [$formation, $doc] = $this->create('BUT', 3);
        $doc->removeNode($this->imposed($doc, 'but-stage')[0], true);

        $found = $this->imposedViolations($formation, $doc, 'but-stage');
        self::assertCount(1, $found);
        self::assertTrue($found[0]->blocking);
        self::assertSame('', $found[0]->scopeNid, 'mono-parcours : la portée est la racine');
    }

    public function testNewParcoursGetsTheMissingImposedElementOnceByPropagation(): void
    {
        [$formation, $doc] = $this->create('BUT', 3, true);
        // un second parcours saisi à la main, sans l'élément imposé
        $parcours = $doc->addNode(null, 'parcours', 'Parcours B');
        foreach ([1, 2, 3] as $_) {
            $annee = $doc->addNode($parcours->nid, 'annee');
            foreach ([1, 2] as $__) {
                $doc->addNode($doc->addNode($annee->nid, 'semestre')->nid, 'ue');
            }
        }
        $doc->reindex();

        $found = $this->imposedViolations($formation, $doc, 'but-stage');
        self::assertCount(1, $found);
        self::assertSame($parcours->nid, $found[0]->scopeNid);

        // écriture directe (hors garde), puis propagation
        $formation->setArbre($doc->dumpTree());
        $this->em->flush();
        $propagator = static::getContainer()->get(ImposedPropagator::class);
        $config = $formation->getTypeDiplome()->latestVersion()->config();
        self::assertSame(1, $propagator->propagate($formation, $config));
        self::assertSame(0, $propagator->propagate($formation, $config));

        $doc = $this->maquette->open($formation);
        $stages = $this->imposed($doc, 'but-stage');
        self::assertCount(2, $stages);
        $inB = array_filter($stages, static function (TreeNode $n) use ($parcours): bool {
            for ($p = $n; null !== $p; $p = $p->getParent()) {
                if ($p->nid === $parcours->nid) {
                    return true;
                }
            }

            return false;
        });
        self::assertCount(1, $inB);
        self::assertSame([], $this->imposedViolations($formation, $doc, 'but-stage'));
    }
}
