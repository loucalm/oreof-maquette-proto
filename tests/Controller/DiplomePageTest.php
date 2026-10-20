<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\TypeDiplome;
use App\Tests\Support\ResetsDatabase;
use App\Tests\Support\SameOriginCsrf;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class DiplomePageTest extends WebTestCase
{
    use ResetsDatabase;
    use SameOriginCsrf;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->sameOrigin($this->client);
        $this->resetDatabase();
    }

    private function type(string $label): TypeDiplome
    {
        return $this->em->getRepository(TypeDiplome::class)->findOneBy(['label' => $label]);
    }

    public function testListShowsDemoTypesWithFormationCount(): void
    {
        $crawler = $this->client->request('GET', '/diplomes');

        self::assertResponseIsSuccessful();
        self::assertCount(5, $crawler->filter('tbody tr'));
        $licence = $crawler->filter('tbody tr')->reduce(
            static fn ($tr) => 'Licence' === trim($tr->filter('td')->eq(0)->filter('.font-medium')->text()),
        );
        self::assertCount(1, $licence);
        self::assertSame('2', trim($licence->filter('[data-col="formations"]')->text()));
        self::assertStringContainsString('3', $licence->filter('[data-col="duration"]')->text());
        self::assertStringContainsString('›', $licence->filter('[data-col="bcc"]')->text());
        // un type utilisé n'offre pas la suppression
        self::assertCount(0, $licence->filter('form[action$="/supprimer"]'));
    }

    public function testArchiveAndUnarchive(): void
    {
        $id = $this->type('Licence')->getId();

        $this->client->request('POST', '/diplomes/'.$id.'/archiver', ['_csrf_token' => $this->csrfToken()]);
        self::assertResponseRedirects('/diplomes');
        $this->em->clear();
        self::assertTrue($this->em->find(TypeDiplome::class, $id)->isArchived());

        $this->client->request('POST', '/diplomes/'.$id.'/desarchiver', ['_csrf_token' => $this->csrfToken()]);
        $this->em->clear();
        self::assertFalse($this->em->find(TypeDiplome::class, $id)->isArchived());
    }

    public function testDeleteRefusedWhenTypeIsUsed(): void
    {
        $id = $this->type('Licence')->getId();

        $this->client->request('POST', '/diplomes/'.$id.'/supprimer', ['_csrf_token' => $this->csrfToken()]);

        self::assertResponseRedirects('/diplomes');
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('formation', $crawler->filter('.flash--error')->text());
        $this->em->clear();
        self::assertNotNull($this->em->find(TypeDiplome::class, $id));
    }

    public function testDeleteUnusedType(): void
    {
        $id = $this->type('Master M2E')->getId();

        $this->client->request('POST', '/diplomes/'.$id.'/supprimer', ['_csrf_token' => $this->csrfToken()]);

        self::assertResponseRedirects('/diplomes');
        $this->em->clear();
        self::assertNull($this->em->find(TypeDiplome::class, $id));
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM type_diplome_version WHERE type_diplome_id = ?', [$id]));
    }

    public function testResponsableIsRedirected(): void
    {
        $this->client->request('POST', '/role/responsable');

        $this->client->request('GET', '/diplomes');
        self::assertResponseRedirects('/');

        $id = $this->type('Master M2E')->getId();
        $this->client->request('POST', '/diplomes/'.$id.'/supprimer', ['_csrf_token' => $this->csrfToken()]);
        self::assertResponseRedirects('/');
        $this->em->clear();
        self::assertNotNull($this->em->find(TypeDiplome::class, $id));
    }

    public function testPostWithoutCsrfTokenIsRefused(): void
    {
        $id = $this->type('Master M2E')->getId();

        $this->client->request('POST', '/diplomes/'.$id.'/supprimer');
        self::assertGreaterThanOrEqual(400, $this->client->getResponse()->getStatusCode(), 'sans jeton, la suppression doit être refusée');

        // jeton présent mais requête d'une autre origine : refusée aussi
        $this->client->setServerParameter('HTTP_SEC_FETCH_SITE', 'cross-site');
        $this->client->request('POST', '/diplomes/'.$id.'/archiver', ['_csrf_token' => $this->csrfToken()]);
        self::assertGreaterThanOrEqual(400, $this->client->getResponse()->getStatusCode());

        $this->em->clear();
        $type = $this->em->find(TypeDiplome::class, $id);
        self::assertNotNull($type);
        self::assertFalse($type->isArchived());
    }

    public function testIndexFormsCarryTheCsrfToken(): void
    {
        $crawler = $this->client->request('GET', '/diplomes');

        $forms = $crawler->filter('form[method="post"][action^="/diplomes/"]');
        self::assertGreaterThan(0, $forms->count());
        self::assertSame($forms->count(), $forms->filter('input[name="_csrf_token"]')->count());
    }
}
