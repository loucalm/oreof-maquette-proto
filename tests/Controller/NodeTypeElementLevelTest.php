<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\NodeType;
use App\Repository\NodeTypeRepository;
use App\Tests\Support\ResetsDatabase;
use App\Tests\Support\SameOriginCsrf;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Le « niveau élément » : un seul type de nœud le porte, jamais une variante. */
final class NodeTypeElementLevelTest extends WebTestCase
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

    private function type(string $key): NodeType
    {
        return $this->em->getRepository(NodeType::class)->findOneBy(['key' => $key]);
    }

    public function testFixturesDesignateEcAsElementLevel(): void
    {
        self::assertTrue($this->type('ec')->isElementLevel());
        self::assertFalse($this->type('ue')->isElementLevel());
        self::assertSame('ec', static::getContainer()->get(NodeTypeRepository::class)->findElementLevel()?->getKey());
    }

    public function testOnlyOneElementLevel(): void
    {
        $ue = $this->type('ue');
        $crawler = $this->client->request('POST', sprintf('/node-types/%d/edit', $ue->getId()), ['label' => 'UE', 'elementLevelForm' => '1', 'elementLevel' => '1']);
        $crawler = $this->client->followRedirect();

        self::assertStringContainsString('Le niveau élément est déjà porté par EC', $crawler->text());
        $this->em->clear();
        self::assertFalse($this->type('ue')->isElementLevel());
        self::assertTrue($this->type('ec')->isElementLevel());
    }

    public function testSavingTheElementLevelTypeKeepsIt(): void
    {
        $ec = $this->type('ec');
        $this->client->request('POST', sprintf('/node-types/%d/edit', $ec->getId()), ['label' => 'EC', 'elementLevelForm' => '1', 'elementLevel' => '1']);

        $this->em->clear();
        self::assertTrue($this->type('ec')->isElementLevel());
    }

    public function testVariantCannotBeElementLevel(): void
    {
        $variant = (new NodeType('ec_variante', 'EC variante'))->setParent($this->type('ec'));
        $this->em->persist($variant);
        $this->em->flush();
        $id = $variant->getId();

        $crawler = $this->client->request('GET', sprintf('/node-types/%d/edit', $id));
        self::assertCount(0, $crawler->filter('input[name="elementLevel"]'), 'la case n\'apparaît pas pour une variante');
        self::assertCount(1, $this->client->request('GET', sprintf('/node-types/%d/edit', $this->type('ue')->getId()))->filter('input[name="elementLevel"]'));

        $this->client->request('POST', sprintf('/node-types/%d/edit', $id), ['label' => 'EC variante', 'elementLevelForm' => '1', 'elementLevel' => '1']);
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Une variante ne peut pas être le niveau élément', $crawler->text());
        $this->em->clear();
        self::assertFalse($this->em->find(NodeType::class, $id)->isElementLevel());
    }
}
