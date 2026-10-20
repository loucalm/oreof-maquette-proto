<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Formation;
use App\Maquette\Maquette;
use App\Tests\Support\FormationFactory;
use App\Tests\Support\ResetsDatabase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class QuickGridTest extends WebTestCase
{
    use ResetsDatabase;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->resetDatabase();
    }

    /** @return array{Formation, string} formation Licence + nid du premier EC */
    private function licenceWithEc(): array
    {
        $factory = FormationFactory::create(static::getContainer());
        $formation = $factory->fromType('L', 3, withLeaves: true);
        $ec = $factory->first(static::getContainer()->get(Maquette::class)->open($formation), 'ec');

        return [$formation, $ec->nid];
    }

    private function reload(Formation $formation): \App\Maquette\Doc\MaquetteDoc
    {
        $this->em->clear();

        return static::getContainer()->get(Maquette::class)->open($this->em->find(Formation::class, $formation->getId()));
    }

    public function testPageListsEditableRows(): void
    {
        [$formation] = $this->licenceWithEc();

        $this->client->request('GET', sprintf('/formations/%d/saisie-rapide', $formation->getId()));

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('tr[data-quick-grid-target="row"] input[name="ects"]');
        self::assertSelectorExists('tr[data-quick-grid-target="row"] input[name="hours[pres][cm]"]');
    }

    public function testSavingARowStoresValuesAndReturnsAncestorTotals(): void
    {
        [$formation, $nid] = $this->licenceWithEc();

        $this->client->request('POST', sprintf('/formations/%d/nodes/%s/saisie-rapide', $formation->getId(), $nid), [
            'ects' => '4,5',
            'hours' => ['pres' => ['cm' => '10', 'td' => ''], 'te' => '5'],
        ]);

        self::assertResponseIsSuccessful();
        $json = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertTrue($json['ok']);
        self::assertSame('15 h', $json['row']['hours']);
        $semestre = array_values(array_filter(array_keys($json['totals']), static fn ($k) => str_starts_with($k, 'ects:')));
        self::assertNotEmpty($semestre);

        $node = $this->reload($formation)->node($nid);
        self::assertSame(4.5, $node->getAttribute('ects'));
        self::assertSame(10.0, $node->getAttribute('hours')['pres']['cm']);
        self::assertArrayNotHasKey('td', $node->getAttribute('hours')['pres']);
    }

    public function testABurstOfSavesCreatesASingleRevision(): void
    {
        [$formation, $nid] = $this->licenceWithEc();
        $url = sprintf('/formations/%d/nodes/%s/saisie-rapide', $formation->getId(), $nid);

        foreach (['1', '2', '3'] as $v) {
            $this->client->request('POST', $url, ['ects' => $v]);
        }

        $this->em->clear();
        $fresh = $this->em->find(Formation::class, $formation->getId());
        $labels = array_map(static fn ($r) => $r->getLabel(), static::getContainer()->get(Maquette::class)->history($fresh));
        self::assertCount(1, array_filter($labels, static fn ($l) => str_starts_with($l, 'Saisie rapide')));
    }

    public function testUnknownNodeIsRejected(): void
    {
        [$formation] = $this->licenceWithEc();

        $this->client->request('POST', sprintf('/formations/%d/nodes/inconnu/saisie-rapide', $formation->getId()), ['ects' => '3']);

        self::assertResponseStatusCodeSame(422);
    }
}
