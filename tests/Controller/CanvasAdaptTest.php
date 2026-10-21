<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Diplome\Spec\CanvasSpec;
use App\Diplome\WizardDraft;
use App\Entity\NodeType;
use App\Entity\TypeDiplome;
use App\Tests\Support\ResetsDatabase;
use App\Tests\Support\SameOriginCsrf;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** « Adapter » et « Modifier » depuis le canevas : une variante est créée et choisie pour l'entrée. */
final class CanvasAdaptTest extends WebTestCase
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

    public function testAdaptCreatesVariantAndSelectsItInVersion(): void
    {
        $but = $this->type('BUT');
        $canvasPath = sprintf('/diplomes/%d/versions/%d/formulaires', $but->getId(), $but->latestVersion()->getId());

        $this->client->request('POST', $canvasPath.'/adapter', ['fiche' => 'formation', 'entry' => 'presentation', '_csrf_token' => $this->csrfToken()]);

        $this->em->clear();
        $variant = $this->em->getRepository(NodeType::class)->findOneBy(['label' => 'Présentation · BUT']);
        self::assertNotNull($variant);
        self::assertSame('param_presentation_formation', $variant->getParent()->getKey());
        self::assertSame($variant->getKey(), $this->type('BUT')->latestVersion()->config()->canvas->entry(CanvasSpec::FORMATION, 'presentation')->form);
        self::assertResponseRedirects(sprintf('/node-types/%d/edit?retour=%s', $variant->getId(), $canvasPath));
    }

    public function testAdaptWithoutTokenIsRefused(): void
    {
        $but = $this->type('BUT');

        $this->client->request('POST', sprintf('/diplomes/%d/versions/%d/formulaires/adapter', $but->getId(), $but->latestVersion()->getId()), ['fiche' => 'formation', 'entry' => 'presentation']);

        self::assertTrue($this->client->getResponse()->isClientError());
        self::assertNull($this->em->getRepository(NodeType::class)->findOneBy(['label' => 'Présentation · BUT']));
    }

    public function testAdaptInWizardSelectsVariantInDraft(): void
    {
        $licence = $this->type('L');
        $this->client->request('GET', '/diplomes/nouveau', ['depuis' => $licence->latestVersion()->getId()]);
        $crawler = $this->client->request('GET', '/diplomes/nouveau/informations');
        $this->client->submit($crawler->filter('form#wizard-form')->form(['informations[label]' => 'Deust', 'informations[sigle]' => 'DEUST']));

        $this->client->request('POST', '/diplomes/nouveau/formulaires/adapter', ['fiche' => 'parcours', 'entry' => 'organisation', '_csrf_token' => $this->csrfToken()]);

        $variant = $this->em->getRepository(NodeType::class)->findOneBy(['label' => 'Organisation et localisation · DEUST']);
        self::assertNotNull($variant);
        $draft = $this->client->getRequest()->getSession()->get(WizardDraft::SESSION_KEY);
        self::assertSame($variant->getKey(), $draft->config()->canvas->entry(CanvasSpec::PARCOURS, 'organisation')->form);
    }

    public function testCanvasRowsOfferAdaptAndModify(): void
    {
        $but = $this->type('BUT');
        $path = sprintf('/diplomes/%d/versions/%d/formulaires', $but->getId(), $but->latestVersion()->getId());

        $crawler = $this->client->request('GET', $path);

        $presentation = $crawler->filter('[data-fiche="formation"] [data-entry="presentation"]');
        self::assertCount(1, $presentation->filter('button[form^="adapt-"]'));
        $socle = $this->em->getRepository(NodeType::class)->findOneBy(['key' => 'param_presentation_formation']);
        self::assertCount(1, $presentation->filter(sprintf('a[href="/node-types/%d/edit?retour=%s"]', $socle->getId(), $path)));
        self::assertCount(0, $crawler->filter('[data-fiche="formation"] [data-entry="structure"] button[form^="adapt-"]'));
        // « Adapter » quitte l'écran : une saisie non enregistrée du canevas est signalée avant
        self::assertSame('dirty-form', $crawler->filter('form#wizard-form')->attr('data-controller'));
    }

    private function type(string $key): TypeDiplome
    {
        return $this->em->getRepository(TypeDiplome::class)->findOneBy(['key' => $key]);
    }
}
