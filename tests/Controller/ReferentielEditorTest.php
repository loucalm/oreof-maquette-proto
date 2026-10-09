<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Referentiel;
use App\Tests\Support\ResetsDatabase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ReferentielEditorTest extends WebTestCase
{
    use ResetsDatabase;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->resetDatabase();
    }

    public function testValuesAreSavedFromLinesInOrder(): void
    {
        $this->client->request('POST', '/administration/referentiels/nouveau', [
            'label' => 'Salles',
            'values_label' => ['Amphi A', '', 'Salle B'],
            'values_code' => ['amphi_a', 'x', ''],
        ]);

        $this->em->clear();
        $referentiel = $this->em->getRepository(Referentiel::class)->findOneBy(['key' => 'salles']);

        self::assertNotNull($referentiel);
        self::assertSame(['amphi_a' => 'Amphi A', 'Salle B' => 'Salle B'], $referentiel->getValues());
    }

    public function testEditPageRendersOneInputPerValue(): void
    {
        $this->client->request('POST', '/administration/referentiels/nouveau', [
            'label' => 'Salles',
            'values_label' => ['Amphi A', '', 'Salle B'],
            'values_code' => ['amphi_a', 'x', ''],
        ]);

        $this->em->clear();
        $referentiel = $this->em->getRepository(Referentiel::class)->findOneBy(['key' => 'salles']);

        $crawler = $this->client->request('GET', sprintf('/administration/referentiels/%d/editer', $referentiel->getId()));

        self::assertResponseIsSuccessful();
        self::assertSame(2, $crawler->filter('[data-values-editor-target="list"] [name="values_label[]"]')->count());
    }
}
