<?php

declare(strict_types=1);

namespace App\Tests\Diplome\Check;

use App\Diplome\Check\StructureChecker;
use App\Diplome\Check\StructureViolation;
use App\Diplome\ImposedPropagator;
use App\Diplome\Spec\VersionConfig;
use App\Diplome\StructureInstantiator;
use App\Diplome\TypeDiplomeResolver;
use App\Entity\Formation;
use App\Entity\TypeDiplome;
use App\Maquette\Doc\MaquetteDoc;
use App\Maquette\Doc\TreeNode;
use App\Maquette\Maquette;
use App\Tests\Support\DatabaseTestCase;

/**
 * Élément imposé en position `all` : attendu sous CHAQUE nœud de son niveau parent, contrôlé hôte par hôte
 * (contrairement à first / last / « n », simplement présents dans la portée).
 */
final class ImposedEveryHostTest extends DatabaseTestCase
{
    private const UID = 'lp-rentree';

    private Maquette $maquette;

    protected function setUp(): void
    {
        parent::setUp();
        $this->maquette = static::getContainer()->get(Maquette::class);
    }

    /** LP d'un an dont la version impose une UE de rentrée dans chaque semestre. */
    private function create(): Formation
    {
        $formation = (new Formation('LP rentrée'))->setTypeDiplome($this->em->getRepository(TypeDiplome::class)->findOneByKey('LP'));
        $this->em->persist($formation);
        $this->em->flush();
        $version = static::getContainer()->get(TypeDiplomeResolver::class)->versionFor($formation);
        $config = $version->config()->toArray();
        $config['structure']['imposed'][] = ['uid' => self::UID, 'typeKey' => 'ue', 'label' => 'UE de rentrée', 'parentLevel' => 'semestre', 'position' => 'all', 'locked' => ['delete']];
        $version->setConfig(VersionConfig::fromArray($config));
        $this->em->flush();

        $doc = $this->maquette->open($formation);
        static::getContainer()->get(StructureInstantiator::class)->instantiate($doc, $version, 1, false);
        $this->maquette->save($formation, $doc, 'Création');

        return $formation;
    }

    private function config(Formation $formation): VersionConfig
    {
        return static::getContainer()->get(TypeDiplomeResolver::class)->configFor($formation);
    }

    /** @return list<TreeNode> */
    private function ofType(MaquetteDoc $doc, string $typeKey): array
    {
        return array_values(array_filter($doc->allNodes(), static fn (TreeNode $n) => $n->typeKey === $typeKey));
    }

    /** @return list<TreeNode> */
    private function imposedUnder(TreeNode $host): array
    {
        return array_values(array_filter($host->getChildren(), static fn (TreeNode $n) => self::UID === $n->getAttribute('_imposed')));
    }

    /** @return list<StructureViolation> */
    private function violations(Formation $formation, MaquetteDoc $doc): array
    {
        return array_values(array_filter(
            static::getContainer()->get(StructureChecker::class)->check($doc, $this->config($formation)),
            static fn (StructureViolation $v) => 'imposed:'.self::UID === $v->ruleKey,
        ));
    }

    public function testEachHostReceivesTheElementAtCreation(): void
    {
        $doc = $this->maquette->open($this->create());

        foreach ($this->ofType($doc, 'semestre') as $semestre) {
            self::assertCount(1, $this->imposedUnder($semestre));
        }
    }

    public function testHostDeprivedOfTheElementIsReportedAlthoughPresentElsewhere(): void
    {
        $formation = $this->create();
        $doc = $this->maquette->open($formation);
        [$s5, $s6] = $this->ofType($doc, 'semestre');
        $doc->removeNode($this->imposedUnder($s5)[0], true);

        $found = $this->violations($formation, $doc);
        self::assertCount(1, $found);
        self::assertSame($s5->nid, $found[0]->scopeNid, 'la violation porte sur l\'hôte privé, pas sur la portée');
        self::assertTrue($found[0]->blocking);
        self::assertCount(1, $this->imposedUnder($s6));
    }

    public function testPropagationCompletesEachHostOnce(): void
    {
        $formation = $this->create();
        $doc = $this->maquette->open($formation);
        [$s5] = $this->ofType($doc, 'semestre');
        $doc->removeNode($this->imposedUnder($s5)[0], true);
        $annee = $doc->addNode(null, 'annee');
        $doc->addNode($annee->nid, 'semestre');
        $doc->addNode($annee->nid, 'semestre');
        $doc->reindex();
        self::assertCount(3, $this->violations($formation, $doc));

        // écriture directe (hors garde), puis propagation : S5 et les deux nouveaux semestres, une seule fois
        $formation->setArbre($doc->dumpTree());
        $this->em->flush();
        $propagator = static::getContainer()->get(ImposedPropagator::class);
        self::assertSame(3, $propagator->propagate($formation, $this->config($formation)));
        self::assertSame(0, $propagator->propagate($formation, $this->config($formation)));

        $doc = $this->maquette->open($formation);
        $semestres = $this->ofType($doc, 'semestre');
        self::assertCount(4, $semestres);
        foreach ($semestres as $semestre) {
            self::assertCount(1, $this->imposedUnder($semestre));
            self::assertTrue($this->imposedUnder($semestre)[0]->isLocked('delete'));
        }
        self::assertSame([], $this->violations($formation, $doc));
        // first / last / « n » restent en présence par portée : le stage n'est pas recopié dans la 2ᵉ année
        self::assertCount(1, array_filter($doc->allNodes(), static fn (TreeNode $n) => 'lp-projet-stage' === $n->getAttribute('_imposed')));
    }
}
