<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Diplome\NextVersionCreator;
use App\Diplome\Spec\CanvasSpec;
use App\Entity\Formation;
use App\Entity\NodeType;
use App\Entity\TypeDiplome;
use App\Tests\Support\FormationFactory;
use App\Tests\Support\ResetsDatabase;
use App\Tests\Support\SameOriginCsrf;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Aperçu : la vraie page de l'éditeur, rendue avec un canevas non enregistré, en lecture seule. */
final class CanvasPreviewTest extends WebTestCase
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

    public function testPreviewShowsUnsavedCanvasOnRealEditor(): void
    {
        $host = $this->previewWithPresentationHidden();

        self::assertResponseRedirects(sprintf('/formations/%d', $host));
        $crawler = $this->client->followRedirect();
        self::assertCount(0, $crawler->filter(sprintf('a[href="/formations/%d/parametre/presentation"]', $host)));
        self::assertCount(1, $crawler->filter('[data-preview-banner]'));

        $this->em->clear();
        $canvas = $this->type('BUT')->latestVersion()->config()->canvas;
        self::assertFalse($canvas?->entry(CanvasSpec::FORMATION, 'presentation')?->hidden ?? false);
    }

    public function testPreviewIsReadOnly(): void
    {
        $host = $this->previewWithPresentationHidden();

        $this->client->request('POST', sprintf('/formations/%d/parametre/organisation', $host), ['b' => ['name' => 'Renommée pendant l’aperçu']]);

        self::assertResponseStatusCodeSame(403);
        $this->em->clear();
        self::assertNotSame('Renommée pendant l’aperçu', $this->em->find(Formation::class, $host)->getName());
    }

    public function testQuitPreviewRestoresRealCanvas(): void
    {
        $host = $this->previewWithPresentationHidden();

        $this->client->request('POST', '/diplomes/apercu/quitter', ['_csrf_token' => $this->csrfToken()]);

        self::assertResponseRedirects($this->editPath($this->type('BUT')));
        $crawler = $this->client->request('GET', sprintf('/formations/%d', $host));
        self::assertCount(1, $crawler->filter(sprintf('a[href="/formations/%d/parametre/presentation"]', $host)));
        self::assertCount(0, $crawler->filter('[data-preview-banner]'));
    }

    public function testPreviewOnlyAffectsHostFormation(): void
    {
        $other = $this->formation('L');
        $this->previewWithPresentationHidden();

        $crawler = $this->client->request('GET', sprintf('/formations/%d', $other->getId()));
        self::assertCount(1, $crawler->filter(sprintf('a[href="/formations/%d/parametre/presentation"]', $other->getId())));
        $this->client->request('POST', sprintf('/formations/%d/parametre/presentation', $other->getId()), ['p' => ['objectif' => 'Former']]);
        self::assertResponseRedirects();
    }

    public function testPreviewWithoutFormationOfVersionUsesAnotherFormation(): void
    {
        $this->formation('L');
        $next = static::getContainer()->get(NextVersionCreator::class)->create($this->type('BUT'));
        $path = sprintf('/diplomes/%d/versions/%d/formulaires', $next->getTypeDiplome()->getId(), $next->getId());
        $values = $this->client->request('GET', $path)->filter('form#wizard-form')->form()->getPhpValues();

        $this->client->request('POST', $path.'/apercu', $values + ['fiche' => 'formation']);

        $this->hostFromRedirect();
    }

    public function testPreviewFromLibraryShowsVariant(): void
    {
        $socle = $this->em->getRepository(NodeType::class)->findOneBy(['key' => 'param_presentation_formation']);
        $this->client->request('POST', sprintf('/administration/node-types/%d/variante', $socle->getId()), ['label' => 'Présentation BUT']);
        $this->em->clear();
        $variant = $this->em->getRepository(NodeType::class)->findOneBy(['label' => 'Présentation BUT']);
        $variant->setFieldSetting('objectif', ['label' => 'But visé par la formation']);
        $this->em->flush();

        $this->client->request('POST', sprintf('/administration/node-types/%d/apercu', $variant->getId()));

        $host = $this->hostFromRedirect();
        $crawler = $this->client->request('GET', sprintf('/formations/%d/parametre/presentation', $host));
        self::assertStringContainsString('But visé par la formation', $crawler->text());
    }

    /** @return int formation hôte de l'aperçu */
    private function previewWithPresentationHidden(): int
    {
        $path = $this->editPath($this->type('BUT'));
        $values = $this->client->request('GET', $path)->filter('form#wizard-form')->form()->getPhpValues();
        $values['canvas']['formation'][1]['hidden'] = '1';

        $this->client->request('POST', $path.'/apercu', $values + ['fiche' => 'formation']);

        return $this->hostFromRedirect();
    }

    private function hostFromRedirect(): int
    {
        self::assertMatchesRegularExpression('#^/formations/\d+$#', (string) $this->client->getResponse()->headers->get('Location'));

        return (int) substr((string) $this->client->getResponse()->headers->get('Location'), \strlen('/formations/'));
    }

    private function formation(string $type): Formation
    {
        return FormationFactory::create(static::getContainer())->fromType($type, 3, 'Formation '.$type);
    }

    private function type(string $key): TypeDiplome
    {
        return $this->em->getRepository(TypeDiplome::class)->findOneBy(['key' => $key]);
    }

    private function editPath(TypeDiplome $type): string
    {
        return sprintf('/diplomes/%d/versions/%d/formulaires', $type->getId(), $type->latestVersion()->getId());
    }
}
