<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\TypeDiplome;
use App\Tests\Support\ResetsDatabase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class DiplomeWizardTest extends WebTestCase
{
    use ResetsDatabase;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->resetDatabase();
    }

    private function licenceVersionId(): int
    {
        $type = $this->em->getRepository(TypeDiplome::class)->findOneBy(['label' => 'Licence']);

        return $type->latestVersion()->getId();
    }

    /** @param array<string, mixed> $values */
    private function submitInformations(array $values)
    {
        $crawler = $this->client->request('GET', '/diplomes/nouveau/informations');

        return $this->client->submit($crawler->filter('form#wizard-form')->form($values));
    }

    public function testCreateDeustFromLicenceChangingOnlyInformations(): void
    {
        $licence = $this->em->getRepository(TypeDiplome::class)->findOneBy(['label' => 'Licence']);
        $expectedStructure = $licence->latestVersion()->config()->structure->toArray();

        $this->client->request('GET', '/diplomes/nouveau', ['depuis' => $this->licenceVersionId()]);
        self::assertResponseRedirects('/diplomes/nouveau/informations');
        $this->submitInformations(['informations[label]' => 'Deust', 'informations[sigle]' => 'DEUST']);
        self::assertResponseRedirects('/diplomes/nouveau/depart');

        $crawler = $this->client->request('GET', '/diplomes/nouveau/recapitulatif');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Deust', $crawler->text());
        self::assertCount(1, $crawler->filter('[data-no-diff]'));

        $this->client->submit($crawler->selectButton('Créer le type de diplôme')->form());
        self::assertResponseRedirects('/diplomes');

        $this->em->clear();
        $deust = $this->em->getRepository(TypeDiplome::class)->findOneBy(['key' => 'DEUST']);
        self::assertNotNull($deust);
        $version = $deust->latestVersion();
        self::assertSame('2026-2027', $version->getAnneeUniversitaire());
        self::assertSame($expectedStructure, $version->config()->structure->toArray());
        self::assertSame($licence->getKey().'@2026-2027', $version->getBasedOn());
    }

    public function testInformationsRequireLabelAndUniqueSigle(): void
    {
        $this->client->request('GET', '/diplomes/nouveau');

        $this->submitInformations(['informations[label]' => '', 'informations[sigle]' => '']);
        self::assertResponseStatusCodeSame(422);

        $this->submitInformations(['informations[label]' => 'Autre licence', 'informations[sigle]' => 'l']);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('déjà utilisé', $this->client->getResponse()->getContent());

        $this->submitInformations(['informations[label]' => 'X', 'informations[sigle]' => 'XY', 'informations[codeApogee]' => 'AB', 'informations[anneeUniversitaire]' => '2026-2028']);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('seconde année', $this->client->getResponse()->getContent());
    }

    public function testLaterStepsLockedUntilInformationsValid(): void
    {
        $this->client->request('GET', '/diplomes/nouveau');
        $this->client->request('GET', '/diplomes/nouveau/recapitulatif');
        self::assertResponseRedirects('/diplomes/nouveau/informations');
    }

    public function testSummaryHighlightsDifferencesWithBaseType(): void
    {
        $this->client->request('GET', '/diplomes/nouveau', ['depuis' => $this->licenceVersionId()]);
        $this->submitInformations(['informations[label]' => 'Deust', 'informations[sigle]' => 'DEUST']);

        $crawler = $this->client->request('GET', '/diplomes/nouveau/formulaires');
        $this->client->submit($crawler->filter('form#wizard-form')->form(['forms[formation_presentation]' => 'param_presentation_parcours']));
        self::assertResponseRedirects('/diplomes/nouveau/structure');

        $crawler = $this->client->request('GET', '/diplomes/nouveau/recapitulatif');
        self::assertCount(1, $crawler->filter('[data-diff] [data-path="forms.formation.presentation"]'));
        self::assertStringContainsString('param_presentation_parcours', $crawler->filter('[data-diff]')->text());
    }

    public function testPlaceholderStepsShowComingSoon(): void
    {
        $this->client->request('GET', '/diplomes/nouveau');
        $this->submitInformations(['informations[label]' => 'Deust', 'informations[sigle]' => 'DEUST']);
        $crawler = $this->client->request('GET', '/diplomes/nouveau/mccc');
        self::assertCount(1, $crawler->filter('[data-coming-soon]'));
    }

    public function testListOffersDuplicateLink(): void
    {
        $id = $this->licenceVersionId();
        $crawler = $this->client->request('GET', '/diplomes');
        self::assertCount(1, $crawler->filter('a[href="/diplomes/nouveau?depuis='.$id.'"]'));
    }

    public function testResponsableIsRedirected(): void
    {
        $this->client->request('POST', '/role/responsable');
        $this->client->request('GET', '/diplomes/nouveau');
        self::assertResponseRedirects('/');
    }
}
