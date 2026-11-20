<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Diplome\WizardDraft;
use App\Entity\NodeType;
use App\Entity\TypeDiplome;
use App\Tests\Support\FormationFactory;
use App\Tests\Support\ResetsDatabase;
use App\Tests\Support\SameOriginCsrf;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Retours de la revue du prototype (novembre) : chemin de retour vers le diplôme en cours, création en cours visible
 * sur la liste, référentiels liés renvoyés à leurs écrans, fiches hors diplôme hors de l'administration, pas de nom
 * de parcours sur la fiche d'une formation sans parcours.
 */
final class PrototypeReviewTest extends WebTestCase
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

    public function testAdaptedFormKeepsTheWayBackToTheCanvasAfterSaving(): void
    {
        $this->startWizard();
        $this->client->request('POST', '/diplomes/nouveau/formulaires/adapter', ['fiche' => 'parcours', 'entry' => 'organisation', '_csrf_token' => $this->csrfToken()]);
        $this->client->followRedirect();
        $variant = $this->em->getRepository(NodeType::class)->findOneBy(['label' => 'Organisation et localisation · DEUST']);
        self::assertNotNull($variant);

        // un enregistrement sur la page du formulaire redirige sans `?retour=` : le chemin reste proposé
        $this->client->request('POST', sprintf('/node-types/%d/edit', $variant->getId()), ['label' => 'Organisation · DEUST']);
        self::assertResponseRedirects(sprintf('/node-types/%d/edit', $variant->getId()));
        $crawler = $this->client->followRedirect();

        self::assertCount(1, $crawler->filter('[data-back-to-canvas] a[href="/diplomes/nouveau/formulaires"]'));
        self::assertCount(1, $crawler->filter('a[href="/diplomes/nouveau/formulaires"]:contains("Annuler")'), '« Annuler » ramène au canevas, pas à la liste des types.');
    }

    public function testDiplomaListOffersToResumeOrDiscardTheCreationInProgress(): void
    {
        $crawler = $this->client->request('GET', '/diplomes');
        self::assertCount(0, $crawler->filter('[data-pending-draft]'));

        $this->startWizard();
        $crawler = $this->client->request('GET', '/diplomes');

        $banner = $crawler->filter('[data-pending-draft]');
        self::assertCount(1, $banner);
        self::assertStringContainsString('Création en cours : Deust', $banner->text());
        self::assertCount(1, $banner->filter('a[href="/diplomes/nouveau"]'));

        $this->client->submit($banner->filter('form')->form());
        self::assertResponseRedirects('/diplomes');
        self::assertNull($this->client->getRequest()->getSession()->get(WizardDraft::SESSION_KEY));
        self::assertCount(0, $this->client->followRedirect()->filter('[data-pending-draft]'));
    }

    public function testLinkedReferentialsPointToTheScreensThatManageThem(): void
    {
        $crawler = $this->client->request('GET', '/administration/referentiels');

        $entites = $crawler->filter('[data-ref-entites]');
        self::assertStringContainsString('Administration › Composantes', $entites->text());
        self::assertStringContainsString('API France Travail (ROME)', $entites->text());
        self::assertCount(0, $entites->filter('a[href*="/referentiels/"]'), 'Rien à modifier ici : la liste vient de son écran de gestion.');
    }

    public function testFichesHorsDiplomeLeaveTheAdministrationForTheOfferMenu(): void
    {
        $crawler = $this->client->request('GET', '/administration');

        self::assertCount(0, $crawler->filter('nav.app-tabs-list a:contains("Fiches matières")'));
        self::assertCount(1, $crawler->filter('a[href="/fiches-matieres/hors-diplome"]:contains("Fiches matières hors diplôme")'));
    }

    public function testFormationWithoutParcoursHasNoParcoursName(): void
    {
        $formation = FormationFactory::create(static::getContainer())->fromType('L', 3, 'Licence de test');

        $crawler = $this->client->request('GET', sprintf('/formations/%d/parametre/organisation', $formation->getId()));

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[name="p[nom]"]'));
        self::assertStringNotContainsString('Nom du parcours', $crawler->text());
    }

    private function startWizard(): void
    {
        $licence = $this->em->getRepository(TypeDiplome::class)->findOneBy(['key' => 'L']);
        $this->client->request('GET', '/diplomes/nouveau', ['depuis' => $licence->latestVersion()->getId()]);
        $crawler = $this->client->request('GET', '/diplomes/nouveau/informations');
        $this->client->submit($crawler->filter('form#wizard-form')->form(['informations[label]' => 'Deust', 'informations[sigle]' => 'DEUST']));
    }
}
