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

    public function testMonoFormationSidebarMergesParcoursEntriesByTheme(): void
    {
        $formation = $this->formation();

        $items = static::getContainer()->get(CanvasResolver::class)->navigation($formation);
        self::assertSame(['1 organisation -', '2 presentation -', '3 bcc -', '4 structure -', '5 fiches_matieres_formation -'], array_map(static fn (NavItem $i) => $i->number.' '.$i->key.' '.($i->group ?? '-'), $items));
        self::assertSame([true, true, false, false, false], array_map(static fn (NavItem $i) => $i->withMono, $items));

        $crawler = $this->client->request('GET', sprintf('/formations/%d', $formation->getId()));
        self::assertCount(0, $crawler->filter(sprintf('a[href^="/formations/%d/parcours-unique/"]', $formation->getId())));
        self::assertStringNotContainsString('Parcours', $crawler->filter('.nav-section')->first()->ancestors()->first()->text());
    }

    public function testEntryPageMergesFormationAndDefaultParcoursWithoutSeparationNorDuplicates(): void
    {
        $formation = $this->formation();

        foreach (['organisation', 'presentation'] as $key) {
            $panel = $this->client->request('GET', sprintf('/formations/%d/parametre/%s', $formation->getId(), $key));
            self::assertSame('param:'.$key, $panel->filter('[data-panel-active]')->attr('data-panel-active'));
            // un seul formulaire, enregistré par la fiche formation
            self::assertCount(1, $panel->filter('form[data-fiche]'));
            self::assertSame(sprintf('/formations/%d/parametre/%s', $formation->getId(), $key), $panel->filter('form[data-fiche]')->attr('action'));
            // chaque champ une seule fois (un groupe de cases à cocher compte pour un)
            $names = array_unique($panel->filter('form [name^="p["], form [name^="b["]')->each(static fn ($i) => preg_replace('/\[\]$/', '', (string) $i->attr('name'))));
            $labels = $panel->filter('form .fld-label')->each(static fn ($l) => trim($l->text()));
            self::assertSame(array_unique($labels), $labels, $key.' : un champ apparaît deux fois.');
            self::assertNotEmpty($names);
        }

        $organisation = $this->client->request('GET', sprintf('/formations/%d/parametre/organisation', $formation->getId()));
        // champs propres au parcours par défaut présents, dans le même formulaire ; leur rubrique garde son rang
        $text = $organisation->filter('form[data-fiche]')->text();
        self::assertLessThan(strpos($text, 'Responsable(s)'), strpos($text, 'Descriptif'));
        self::assertCount(1, $organisation->filter('form [name="b[ectsTotal]"]'));
        self::assertCount(1, $organisation->filter('form [name="p[respParcours]"]'));

        $editor = $this->client->request('GET', sprintf('/formations/%d', $formation->getId()));
        self::assertCount(1, $editor->filter('a.nav-section[data-nav-key="organisation"]'));
        self::assertCount(0, $editor->filter('a.nav-section[data-nav-key="parcours.organisation"]'));
    }

    public function testSharedFieldIsSavedInBothFiches(): void
    {
        $formation = $this->formation();

        $this->client->request('POST', sprintf('/formations/%d/parametre/presentation', $formation->getId()), ['p' => ['objectif' => 'Former des développeurs', 'langue' => 'Français']]);

        $fresh = $this->reload($formation);
        self::assertSame('Former des développeurs', $fresh->getParametre('presentation')['objectif'] ?? null);
        self::assertSame('Former des développeurs', $fresh->getParametre('parcours.presentation')['objectif'] ?? null, 'Le parcours par défaut garde la valeur (passage en multi-parcours).');
        self::assertSame('Français', $fresh->getParametre('parcours.presentation')['langue'] ?? null);
    }

    public function testSavingTheParcoursPartReturnsToTheSharedEntry(): void
    {
        $formation = $this->formation();

        $this->client->request('POST', sprintf('/formations/%d/parcours-unique/parametre/presentation', $formation->getId()), ['p' => ['langue' => 'Français']]);
        self::assertResponseRedirects(sprintf('/formations/%d?param=presentation', $formation->getId()));
        $crawler = $this->client->followRedirect();

        self::assertSame(sprintf('/formations/%d/parametre/presentation', $formation->getId()), $crawler->filter('turbo-frame#node-panel')->attr('src'));
    }

    public function testSharedEntryStatusCombinesBothForms(): void
    {
        $formation = $this->formation();
        $navigation = static::getContainer()->get(\App\Formulaire\FicheNavigation::class);
        $fiches = static::getContainer()->get(\App\Formulaire\Fiche\FicheFactory::class);
        $entries = static::getContainer()->get(\App\Formulaire\EntryCompletion::class);

        $own = $entries->status($fiches->formation($formation), 'organisation');
        $mono = $entries->status($fiches->mono($formation), 'organisation');
        self::assertSame($own === $mono ? $own : 'incomplete', $navigation->entryStatus($formation, 'organisation'));

        $this->client->request('POST', sprintf('/formations/%d/parcours-unique/parametre/organisation', $formation->getId()), ['p' => ['regimes' => ['FI']]], [], ['HTTP_ACCEPT' => 'application/json']);
        $json = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame($navigation->entryStatus($this->reload($formation), 'organisation'), $json['status']);
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
        // pas de nom de parcours sur la fiche du parcours unique : un « nom » posté est ignoré
        $this->client->request('POST', sprintf('/formations/%d/parcours-unique/parametre/organisation', $formation->getId()), ['p' => ['nom' => 'Parcours unique', 'lieu' => 'Campus Nord'], 'b' => ['ectsTotal' => '180']]);
        self::assertArrayNotHasKey('nom', $this->reload($formation)->getParametre('parcours.organisation'));

        $this->client->request('POST', sprintf('/formations/%d/settings', $formation->getId()), ['avecParcours' => '1']);
        $fresh = $this->reload($formation);
        $parcours = static::getContainer()->get(Maquette::class)->open($fresh)->parcoursNodes()[0];
        self::assertSame('Licence Informatique', $parcours->getLabel(), 'Le parcours créé prend le nom de la formation.');
        self::assertSame(180, $parcours->getAttribute('ects'));
        self::assertSame('Campus Nord', $parcours->getParametre('organisation')['lieu'] ?? null);
        self::assertSame([], $fresh->getParametre('parcours.organisation'));

        $this->client->request('POST', sprintf('/formations/%d/settings', $formation->getId()), ['avecParcours' => '0']);
        $back = $this->reload($formation)->getParametre('parcours.organisation');
        self::assertSame('Campus Nord', $back['lieu'] ?? null);
        self::assertSame('Licence Informatique', $back['nom'] ?? null, 'Le nom du parcours est gardé pour un retour en multi-parcours.');
    }

    public function testSwitchingToMonoKeepsDataOfOtherParcours(): void
    {
        $formation = FormationFactory::create(static::getContainer())->fromType('L', 3, 'Licence à deux parcours', true);
        $maquette = static::getContainer()->get(Maquette::class);
        $doc = $maquette->open($formation);
        $a = $doc->parcoursNodes()[0];
        $b = $doc->addNode(null, 'parcours', 'Parcours B');
        $a->setParametre('organisation', ['lieu' => 'Campus A']);
        $b->setParametre('organisation', ['lieu' => 'Campus B']);
        $maquette->save($formation, $doc);

        $this->client->request('POST', sprintf('/formations/%d/settings', $formation->getId()), ['avecParcours' => '0']);

        $fresh = $this->reload($formation);
        self::assertSame('Campus A', $fresh->getParametre('parcours.organisation')['lieu'] ?? null);
        self::assertStringContainsString('Campus B', json_encode($fresh->getParametre('parcours.retires'), JSON_UNESCAPED_UNICODE));
    }

    public function testSwitchingToMultiWithoutElementsStillCreatesTheParcours(): void
    {
        $formation = $this->formation();
        $maquette = static::getContainer()->get(Maquette::class);
        $doc = $maquette->open($formation);
        foreach ($doc->pedagogicalRoots() as $root) {
            $doc->removeNode($root);
        }
        $maquette->save($formation, $doc);
        $this->client->request('POST', sprintf('/formations/%d/parcours-unique/parametre/organisation', $formation->getId()), ['p' => ['lieu' => 'Campus Sud']]);

        $this->client->request('POST', sprintf('/formations/%d/settings', $formation->getId()), ['avecParcours' => '1']);

        $parcours = static::getContainer()->get(Maquette::class)->open($this->reload($formation))->parcoursNodes();
        self::assertCount(1, $parcours);
        self::assertSame('Campus Sud', $parcours[0]->getParametre('organisation')['lieu'] ?? null);
    }

    public function testSwitchingToMonoKeepsTheFormationEctsTotal(): void
    {
        $formation = FormationFactory::create(static::getContainer())->fromType('L', 3, 'Licence', true);
        $formation->setEctsTotal(180);
        $maquette = static::getContainer()->get(Maquette::class);
        $doc = $maquette->open($formation);
        $doc->parcoursNodes()[0]->setAttribute('ects', 120);
        $maquette->save($formation, $doc);

        $this->client->request('POST', sprintf('/formations/%d/settings', $formation->getId()), ['avecParcours' => '0']);

        self::assertSame(180, $this->reload($formation)->getEctsTotal());
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
