<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Formation;
use App\Maquette\Maquette;
use App\Tests\Support\FormationFactory;
use App\Tests\Support\ResetsDatabase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class EditorActionsTest extends WebTestCase
{
    use ResetsDatabase;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->resetDatabase();
    }

    private function licence(): Formation
    {
        return FormationFactory::create(static::getContainer())->fromTemplate('licence_lmd');
    }

    /** @return list<string> nids des enfants de $nid, dans l'ordre */
    private function childNids(Formation $formation, string $nid): array
    {
        $this->em->clear();
        $fresh = $this->em->find(Formation::class, $formation->getId());
        $doc = static::getContainer()->get(Maquette::class)->open($fresh);

        return array_map(static fn ($n) => $n->nid, $doc->node($nid)->getChildren());
    }

    private function firstOfType(Formation $formation, string $type): \App\Maquette\Doc\TreeNode
    {
        $doc = static::getContainer()->get(Maquette::class)->open($formation);

        return FormationFactory::create(static::getContainer())->first($doc, $type);
    }

    public function testShiftMovesANodeAmongItsSiblings(): void
    {
        $formation = $this->licence();
        $annee = $this->firstOfType($formation, 'annee');
        [$s1, $s2] = array_map(static fn ($n) => $n->nid, $annee->getChildren());

        $this->client->request('POST', sprintf('/formations/%d/nodes/%s/shift/down', $formation->getId(), $s1));

        self::assertResponseRedirects();
        self::assertSame([$s2, $s1], $this->childNids($formation, $annee->nid));

        $this->client->request('POST', sprintf('/formations/%d/nodes/%s/shift/up', $formation->getId(), $s1));
        self::assertSame([$s1, $s2], $this->childNids($formation, $annee->nid));
    }

    public function testShiftAtTheEdgeDoesNothing(): void
    {
        $formation = $this->licence();
        $annee = $this->firstOfType($formation, 'annee');
        $before = array_map(static fn ($n) => $n->nid, $annee->getChildren());

        $this->client->request('POST', sprintf('/formations/%d/nodes/%s/shift/up', $formation->getId(), $before[0]));

        self::assertSame($before, $this->childNids($formation, $annee->nid));
    }

    public function testAddAfterInsertsRightBehindTheReferenceSibling(): void
    {
        $formation = $this->licence();
        $ue = $this->firstOfType($formation, 'ue');
        $ec = $ue->getChildren()[0];
        // deux EC pour vérifier l'insertion au milieu
        $this->client->request('POST', sprintf('/formations/%d/nodes', $formation->getId()), [
            'parentId' => $ue->nid, 'typeKey' => 'ec', 'label' => 'Dernier',
        ]);
        $this->client->request('POST', sprintf('/formations/%d/nodes', $formation->getId()), [
            'parentId' => $ue->nid, 'typeKey' => 'ec', 'label' => 'Inséré', 'afterId' => $ec->nid,
        ]);

        $this->em->clear();
        $doc = static::getContainer()->get(Maquette::class)->open($this->em->find(Formation::class, $formation->getId()));
        $labels = array_map(static fn ($n) => $n->label, $doc->node($ue->nid)->getChildren());
        self::assertSame([$ec->label, 'Inséré', 'Dernier'], $labels);
    }

    public function testOpenRedirectsToTheEditorFocusedOnTheNode(): void
    {
        $formation = $this->licence();
        $ue = $this->firstOfType($formation, 'ue');

        $this->client->request('GET', sprintf('/formations/%d/nodes/%s/ouvrir', $formation->getId(), $ue->nid));

        self::assertResponseRedirects(sprintf('/formations/%d?focus=%s', $formation->getId(), $ue->nid));
    }

    public function testCheckPageReportsEctsGapsAndIncompleteSections(): void
    {
        $formation = $this->licence();

        $this->client->request('GET', sprintf('/formations/%d/verifier', $formation->getId()));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'ECTS : 0 / 30');
        self::assertSelectorTextContains('main', 'Paramètres à compléter');
    }

    public function testPanelListsMissingFieldsAndHasNoNestedForm(): void
    {
        $formation = $this->licence();
        $ec = $this->firstOfType($formation, 'ec');

        $crawler = $this->client->request('GET', sprintf('/formations/%d/nodes/%s', $formation->getId(), $ec->nid));

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.issues-box .issue-chip');
        self::assertCount(0, $crawler->filter('form form'));
    }
}
