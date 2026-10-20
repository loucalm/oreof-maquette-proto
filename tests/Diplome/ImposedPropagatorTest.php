<?php

declare(strict_types=1);

namespace App\Tests\Diplome;

use App\Diplome\ImposedPropagator;
use App\Diplome\Spec\VersionConfig;
use App\Diplome\StructureInstantiator;
use App\Entity\Formation;
use App\Entity\TypeDiplome;
use App\Maquette\Doc\TreeNode;
use App\Maquette\Maquette;
use App\Tests\Support\DatabaseTestCase;

final class ImposedPropagatorTest extends DatabaseTestCase
{
    private function lpFormation(): array
    {
        $type = $this->em->getRepository(TypeDiplome::class)->findOneByKey('LP');
        $formation = new Formation('LP test');
        $formation->setTypeDiplome($type);
        $this->em->persist($formation);
        $this->em->flush();
        $maquette = static::getContainer()->get(Maquette::class);
        $doc = $maquette->open($formation);
        static::getContainer()->get(StructureInstantiator::class)->instantiate($doc, $type->latestVersion(), 1, false);
        $maquette->save($formation, $doc, 'Création');

        return [$formation, $type->latestVersion()->config()->toArray()];
    }

    /** @return list<TreeNode> */
    private function imposedNodes(Formation $formation, string $uid): array
    {
        $doc = static::getContainer()->get(Maquette::class)->open($formation);

        return array_values(array_filter($doc->allNodes(), static fn (TreeNode $n) => $uid === $n->getAttribute('_imposed')));
    }

    public function testPropagationAddsMissingImposedElement(): void
    {
        [$formation, $config] = $this->lpFormation();
        $config['structure']['imposed'][] = ['uid' => 'lp-extra', 'typeKey' => 'ue', 'label' => 'UE imposée', 'parentLevel' => 'semestre', 'position' => 'last', 'locked' => ['delete', 'duplicate']];
        $propagator = static::getContainer()->get(ImposedPropagator::class);

        self::assertSame(1, $propagator->propagate($formation, VersionConfig::fromArray($config)));

        $nodes = $this->imposedNodes($formation, 'lp-extra');
        self::assertCount(1, $nodes);
        self::assertSame('UE imposée', $nodes[0]->label);
        self::assertTrue($nodes[0]->isLocked('delete'));
        self::assertTrue($nodes[0]->isLocked('duplicate'));
    }

    public function testPropagationIsIdempotentAndReappliesLocks(): void
    {
        [$formation, $config] = $this->lpFormation();
        $propagator = static::getContainer()->get(ImposedPropagator::class);

        // l'élément imposé d'origine est déjà là : rien à faire
        self::assertSame(0, $propagator->propagate($formation, VersionConfig::fromArray($config)));

        // un verrou de plus : un nœud touché, pas de doublon
        $config['structure']['imposed'][0]['locked'] = ['delete', 'duplicate', 'add'];
        self::assertSame(1, $propagator->propagate($formation, VersionConfig::fromArray($config)));
        $nodes = $this->imposedNodes($formation, 'lp-projet-stage');
        self::assertCount(1, $nodes);
        self::assertTrue($nodes[0]->isLocked('add'));
    }

    public function testManualNodeIsNeverTakenForTheImposedOne(): void
    {
        [$formation, $config] = $this->lpFormation();
        $maquette = static::getContainer()->get(Maquette::class);
        $doc = $maquette->open($formation);
        $imposed = $this->imposedNodes($formation, 'lp-projet-stage')[0];
        // une UE saisie à la main, en tête du même semestre : l'identité (`_imposed`) fait foi, pas la position
        $manual = $doc->addNode($imposed->parent->nid, 'ue', 'UE manuelle');
        $doc->moveNode($manual, $doc->node($imposed->parent->nid), 0);
        $maquette->save($formation, $doc);

        $config['structure']['imposed'][0]['locked'] = ['delete', 'duplicate', 'add'];
        static::getContainer()->get(ImposedPropagator::class)->propagate($formation, VersionConfig::fromArray($config));

        $doc = $maquette->open($formation);
        self::assertSame([], $doc->node($manual->nid)->locked);
        self::assertCount(1, $this->imposedNodes($formation, 'lp-projet-stage'));
    }
}
