<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Diplome\Spec\CanvasEntry;
use App\Diplome\Spec\CanvasSpec;
use App\Entity\Formation;
use App\Maquette\Maquette;
use App\Tests\Support\EditsCanvas;
use App\Tests\Support\FormationFactory;
use App\Tests\Support\ResetsDatabase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Barres latérales, page Vérifier, page Voir et export enrichi suivent le canevas de la version. */
final class FicheNavigationTest extends WebTestCase
{
    use EditsCanvas;
    use ResetsDatabase;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->resetDatabase();
    }

    public function testEditorSidebarFollowsCanvas(): void
    {
        $formation = $this->formation();
        $this->hideEntry($formation, CanvasSpec::FORMATION, 'presentation');
        $this->changeEntry($formation, CanvasSpec::FORMATION, 'organisation', static fn (CanvasEntry $e) => new CanvasEntry($e->key, $e->form, null, 'Organisation', false));
        $this->addEntry($formation, CanvasSpec::FORMATION, new CanvasEntry('alternance', 'param_presentation_formation', null, 'Alternance', false), 1);

        $crawler = $this->client->request('GET', sprintf('/formations/%d', $formation->getId()));

        self::assertResponseIsSuccessful();
        $items = $crawler->filter('.nav-section')->each(static fn ($a) => trim(preg_replace('/\s+/', ' ', $a->text())));
        self::assertSame(['1 | Organisation', '2 | Alternance', '3 | Référentiel de compétences (BCC)', '4 | Configuration de la structure'], \array_slice($items, 0, 4));
        self::assertCount(0, $crawler->filter(sprintf('a[href="/formations/%d/parametre/presentation"]', $formation->getId())));
        self::assertCount(1, $crawler->filter(sprintf('a[href="/formations/%d/parametre/alternance"]', $formation->getId())));
    }

    public function testEditorOpensTheEntryNamedByParam(): void
    {
        $formation = $this->formation();
        $this->addEntry($formation, CanvasSpec::FORMATION, new CanvasEntry('alternance', 'param_presentation_formation', null, 'Alternance', false));

        $crawler = $this->client->request('GET', sprintf('/formations/%d?param=alternance', $formation->getId()));

        self::assertSame(sprintf('/formations/%d/parametre/alternance', $formation->getId()), $crawler->filter('turbo-frame#node-panel')->attr('src'));
    }

    public function testParcoursEditorSidebarFollowsCanvas(): void
    {
        $formation = $this->formation(true);
        $this->hideEntry($formation, CanvasSpec::PARCOURS, 'bcc');
        $parcours = static::getContainer()->get(Maquette::class)->open($formation)->parcoursNodes()[0];

        $crawler = $this->client->request('GET', sprintf('/formations/%d/parcours/%s', $formation->getId(), $parcours->getId()));

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('a[href$="/bcc"]'));
        $items = $crawler->filter('.nav-section')->each(static fn ($a) => trim(preg_replace('/\s+/', ' ', $a->text())));
        self::assertSame('3 | Positionnement & ramification', $items[2]);
    }

    public function testCheckPageListsOnlyVisibleEntries(): void
    {
        $formation = $this->formation();
        $this->hideEntry($formation, CanvasSpec::FORMATION, 'presentation');

        $crawler = $this->client->request('GET', sprintf('/formations/%d/verifier', $formation->getId()));

        // entrées de la fiche formation (sans contexte) ; celles du parcours unique portent le contexte « Parcours »
        $labels = $crawler->filter('li')->each(static fn ($li) => trim(preg_replace('/\s+/', ' ', $li->filter('.flex.items-center.gap-2.text-sm')->text(''))));
        self::assertContains('Organisation et localisation', $labels);
        self::assertNotContains('Présentation', $labels);
        self::assertContains('Présentation · Parcours', $labels);
    }

    public function testViewPageOmitsHiddenEntry(): void
    {
        $formation = $this->formation();
        $this->setParam($formation, 'presentation', ['objectif' => 'Objectif très particulier']);
        $this->client->request('GET', sprintf('/formations/%d/voir', $formation->getId()));
        self::assertStringContainsString('Objectif très particulier', (string) $this->client->getResponse()->getContent());

        $this->hideEntry($formation, CanvasSpec::FORMATION, 'presentation');
        $this->client->request('GET', sprintf('/formations/%d/voir', $formation->getId()));

        self::assertStringNotContainsString('Objectif très particulier', (string) $this->client->getResponse()->getContent());
    }

    public function testEnrichedExportOmitsHiddenEntryButRawKeepsIt(): void
    {
        $formation = $this->formation();
        $this->setParam($formation, 'presentation', ['objectif' => 'Former']);
        $this->hideEntry($formation, CanvasSpec::FORMATION, 'presentation');

        $this->client->request('GET', sprintf('/formations/%d/export.json?view=enriched', $formation->getId()));
        $enriched = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->client->request('GET', sprintf('/formations/%d/export.json', $formation->getId()));
        $raw = json_decode((string) $this->client->getResponse()->getContent(), true);

        self::assertArrayNotHasKey('presentation', $enriched['formation']['parametres']);
        self::assertSame('Former', $raw['parametres']['presentation']['objectif']);
    }

    public function testHiddenBccIsAbsentFromViewAndExport(): void
    {
        $formation = $this->formation();
        $crawler = $this->client->request('GET', sprintf('/formations/%d/voir', $formation->getId()));
        self::assertCount(1, $crawler->filter('[data-rotree-id-param="bcc"]'));

        $this->hideEntry($formation, CanvasSpec::FORMATION, 'bcc');
        $crawler = $this->client->request('GET', sprintf('/formations/%d/voir', $formation->getId()));
        self::assertCount(0, $crawler->filter('[data-rotree-id-param="bcc"], [data-node="bcc"]'));

        $this->client->request('GET', sprintf('/formations/%d/export.json?view=enriched', $formation->getId()));
        self::assertSame([], json_decode((string) $this->client->getResponse()->getContent(), true)['bcc']);
    }

    private function formation(bool $avecParcours = false): Formation
    {
        return FormationFactory::create(static::getContainer())->fromType('L', 3, 'Licence de test', $avecParcours);
    }

    /** @param array<string, mixed> $data */
    private function setParam(Formation $formation, string $key, array $data): void
    {
        $maquette = static::getContainer()->get(Maquette::class);
        $doc = $maquette->open($formation);
        $doc->setFormationParam($key, $data);
        $maquette->save($formation, $doc);
    }
}
