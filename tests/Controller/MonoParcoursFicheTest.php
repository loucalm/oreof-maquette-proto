<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Formation;
use App\Formulaire\CanvasResolver;
use App\Formulaire\NavItem;
use App\Maquette\Maquette;
use App\Tests\Support\FormationFactory;
use App\Tests\Support\ResetsDatabase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Une formation sans parcours remplit la fiche parcours du canevas ; plus de champs de parcours dans la configuration de la structure. */
final class MonoParcoursFicheTest extends WebTestCase
{
    use ResetsDatabase;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->resetDatabase();
    }

    public function testStructureScreenNoLongerShowsParcoursFields(): void
    {
        $formation = $this->formation();

        $crawler = $this->client->request('GET', sprintf('/formations/%d/structure', $formation->getId()));

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[name="p[intituleMono]"], [name="regimes[]"], [name="p[langue]"]'));
        self::assertCount(0, $crawler->filter('input[type=number][name="ectsTotal"]'));
    }

    public function testMonoFormationSidebarShowsParcoursGroup(): void
    {
        $formation = $this->formation();

        $items = array_map(static fn (NavItem $i) => $i->number.' '.$i->key.' '.($i->group ?? '-'), static::getContainer()->get(CanvasResolver::class)->navigation($formation));
        self::assertSame(['1 organisation -', '2 presentation -', '3 bcc -', '4 structure -', '5 organisation parcours', '6 presentation parcours'], $items);

        $crawler = $this->client->request('GET', sprintf('/formations/%d', $formation->getId()));
        self::assertCount(1, $crawler->filter(sprintf('a[href="/formations/%d/parcours-unique/parametre/organisation"]', $formation->getId())));
        self::assertStringContainsString('Parcours', $crawler->filter('.nav-section')->eq(4)->ancestors()->first()->text());
    }

    public function testEditorOpensTheMonoParcoursEntryAfterSaving(): void
    {
        $formation = $this->formation();

        $this->client->request('POST', sprintf('/formations/%d/parcours-unique/parametre/presentation', $formation->getId()), ['p' => ['langue' => 'Français']]);
        self::assertResponseRedirects(sprintf('/formations/%d?param=parcours.presentation', $formation->getId()));
        $crawler = $this->client->followRedirect();

        self::assertSame(sprintf('/formations/%d/parcours-unique/parametre/presentation', $formation->getId()), $crawler->filter('turbo-frame#node-panel')->attr('src'));
    }

    public function testMonoEntryPanelMarksItsOwnNavLink(): void
    {
        $formation = $this->formation();

        $panel = $this->client->request('GET', sprintf('/formations/%d/parcours-unique/parametre/organisation', $formation->getId()));
        self::assertSame('param:parcours.organisation', $panel->filter('[data-panel-active]')->attr('data-panel-active'));

        $editor = $this->client->request('GET', sprintf('/formations/%d', $formation->getId()));
        self::assertCount(1, $editor->filter('a.nav-section[data-nav-key="parcours.organisation"]'));
        self::assertCount(1, $editor->filter('a.nav-section[data-nav-key="organisation"]'));
    }

    public function testMonoParcoursEntrySavesOnFormation(): void
    {
        $formation = $this->formation();

        $this->client->request('POST', sprintf('/formations/%d/parcours-unique/parametre/organisation', $formation->getId()), ['p' => ['regimes' => ['FI']]]);

        self::assertSame(['FI'], $this->reload($formation)->getParametre('parcours.organisation')['regimes'] ?? null);
    }

    public function testMonoEctsTotalWritesFormation(): void
    {
        $formation = $this->formation();

        $this->client->request('POST', sprintf('/formations/%d/parcours-unique/parametre/organisation', $formation->getId()), ['b' => ['ectsTotal' => '180']]);

        self::assertSame(180, $this->reload($formation)->getEctsTotal());
    }

    public function testViewPageShowsMonoParcoursEntries(): void
    {
        $formation = $this->formation();
        $this->client->request('POST', sprintf('/formations/%d/parcours-unique/parametre/presentation', $formation->getId()), ['p' => ['debouches' => 'Développeur web']]);

        $this->client->request('GET', sprintf('/formations/%d/voir', $formation->getId()));

        self::assertStringContainsString('Développeur web', (string) $this->client->getResponse()->getContent());
    }

    public function testSwitchingParcoursModeKeepsParcoursData(): void
    {
        $formation = FormationFactory::create(static::getContainer())->fromType('L', 3, 'Licence Informatique', false, true);
        $this->client->request('POST', sprintf('/formations/%d/parcours-unique/parametre/organisation', $formation->getId()), ['p' => ['nom' => 'Parcours unique', 'lieu' => 'Campus Nord'], 'b' => ['ectsTotal' => '180']]);

        $this->client->request('POST', sprintf('/formations/%d/settings', $formation->getId()), ['avecParcours' => '1']);
        $fresh = $this->reload($formation);
        $parcours = static::getContainer()->get(Maquette::class)->open($fresh)->parcoursNodes()[0];
        self::assertSame('Parcours unique', $parcours->getLabel());
        self::assertSame(180, $parcours->getAttribute('ects'));
        self::assertSame('Campus Nord', $parcours->getParametre('organisation')['lieu'] ?? null);
        self::assertSame([], $fresh->getParametre('parcours.organisation'));

        $this->client->request('POST', sprintf('/formations/%d/settings', $formation->getId()), ['avecParcours' => '0']);
        $back = $this->reload($formation)->getParametre('parcours.organisation');
        self::assertSame('Campus Nord', $back['lieu'] ?? null);
        self::assertSame('Parcours unique', $back['nom'] ?? null);
    }

    private function formation(): Formation
    {
        return FormationFactory::create(static::getContainer())->fromType('L', 3, 'Licence de test');
    }

    private function reload(Formation $formation): Formation
    {
        $this->em->clear();

        return $this->em->find(Formation::class, $formation->getId());
    }
}
