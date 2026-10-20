<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\TypeDiplome;
use App\Tests\Support\ResetsDatabase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class DiplomePageTest extends WebTestCase
{
    use ResetsDatabase;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
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

        $this->client->request('POST', '/diplomes/'.$id.'/archiver');
        self::assertResponseRedirects('/diplomes');
        $this->em->clear();
        self::assertTrue($this->em->find(TypeDiplome::class, $id)->isArchived());

        $this->client->request('POST', '/diplomes/'.$id.'/desarchiver');
        $this->em->clear();
        self::assertFalse($this->em->find(TypeDiplome::class, $id)->isArchived());
    }

    public function testDeleteRefusedWhenTypeIsUsed(): void
    {
        $id = $this->type('Licence')->getId();

        $this->client->request('POST', '/diplomes/'.$id.'/supprimer');

        self::assertResponseRedirects('/diplomes');
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('formation', $crawler->filter('.flash--error')->text());
        $this->em->clear();
        self::assertNotNull($this->em->find(TypeDiplome::class, $id));
    }

    public function testDeleteUnusedType(): void
    {
        $id = $this->type('Master M2E')->getId();

        $this->client->request('POST', '/diplomes/'.$id.'/supprimer');

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
        $this->client->request('POST', '/diplomes/'.$id.'/supprimer');
        self::assertResponseRedirects('/');
        $this->em->clear();
        self::assertNotNull($this->em->find(TypeDiplome::class, $id));
    }
}
