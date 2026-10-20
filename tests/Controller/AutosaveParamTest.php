<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Formation;
use App\Tests\Support\FormationFactory;
use App\Tests\Support\ResetsDatabase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Enregistrement en direct des sections « Paramètre » (Stimulus autosave) :
 * la requête AJAX renvoie le statut recalculé au lieu d'une redirection,
 * le POST classique garde son comportement historique.
 */
final class AutosaveParamTest extends WebTestCase
{
    use ResetsDatabase;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->resetDatabase();
    }

    public function testParamSaveReturnsStatusAsJsonForAjaxRequests(): void
    {
        $formation = FormationFactory::create(static::getContainer())->fromType('L', 3, withLeaves: true);

        $this->client->request(
            'POST',
            sprintf('/formations/%d/parametre/presentation', $formation->getId()),
            ['p' => ['objectif' => 'Texte']],
            [],
            ['HTTP_ACCEPT' => 'application/json'],
        );

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($payload);
        self::assertArrayHasKey('status', $payload);
        self::assertIsString($payload['status']);

        $this->em->clear();
        $fresh = $this->em->find(Formation::class, $formation->getId());
        self::assertSame('Texte', $fresh->getParametre('presentation')['objectif'] ?? null);
    }

    public function testParamSaveRedirectsWithoutJsonAcceptHeader(): void
    {
        $formation = FormationFactory::create(static::getContainer())->fromType('L', 3, withLeaves: true);

        $this->client->request(
            'POST',
            sprintf('/formations/%d/parametre/presentation', $formation->getId()),
            ['p' => ['objectif' => 'Texte']],
        );

        self::assertResponseStatusCodeSame(302);
    }
}
