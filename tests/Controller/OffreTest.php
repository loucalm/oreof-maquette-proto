<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Formation;
use App\Entity\TypeDiplome;
use App\Enum\FormationPhase;
use App\Maquette\Maquette;
use App\Offre\OffreFormation;
use App\Tests\Support\ResetsDatabase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Gestion de l'offre : tableau par composante, page « Configurer » d'une formation, plateformes du diplôme. */
final class OffreTest extends WebTestCase
{
    use ResetsDatabase;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->resetDatabase();
    }

    public function testBoardListsFormationsByComposanteWithTheirOffer(): void
    {
        $crawler = $this->client->request('GET', '/offre');

        self::assertResponseIsSuccessful();
        self::assertSame('page', $crawler->filter('[data-tab-link="offre"]')->attr('aria-current'));
        self::assertCount(4, $crawler->filter('[data-offre-formation]'));
        self::assertGreaterThan(0, $crawler->filter('[data-offre-table-target="group"]')->count());

        // BUT de démo : sans parcours, 3 années ouvertes (96 + 84 + 84), Parcoursup, offre publiée
        $but = $this->formation('BUT Informatique (démo)');
        $row = $crawler->filter(sprintf('[data-offre-formation="%d"]', $but->getId()));
        self::assertSame('264', $row->attr('data-capacite'));
        self::assertStringContainsString('Parcoursup', $row->text());
        self::assertStringContainsString('Publié', $row->text());
        self::assertCount(3, $crawler->filter(sprintf('[data-annee-of="%d-_formation"]', $but->getId())));
    }

    public function testBoardFilters(): void
    {
        $crawler = $this->client->request('GET', '/offre', ['statut' => 'publie']);
        self::assertSame(['BUT Informatique (démo)'], $this->names($crawler));

        $crawler = $this->client->request('GET', '/offre', ['plateforme' => 'Parcoursup']);
        self::assertNotContains('Certificat Data Analyst (6 semaines)', $this->names($crawler));

        // le parcours « Génie logiciel » de la démo n'a pas de capacité
        $crawler = $this->client->request('GET', '/offre', ['statut' => 'anomalie']);
        self::assertContains('Licence Sciences & Technologies (démo avec parcours)', $this->names($crawler));

        $crawler = $this->client->request('GET', '/offre', ['q' => 'génie']);
        self::assertSame(['Licence Sciences & Technologies (démo avec parcours)'], $this->names($crawler));
    }

    public function testConfigureSavesOpeningCapacitiesAndPlatforms(): void
    {
        $formation = $this->formation('Licence Informatique (démo)');
        $offre = static::getContainer()->get(OffreFormation::class)->describe($formation);
        $annee = $offre['parcours'][0]['annees'][0];
        self::assertSame(['Parcoursup'], array_column($annee['plateformes'], 'key'), 'Parcoursup en première année de Licence');

        $crawler = $this->client->request('GET', sprintf('/offre/formations/%d', $formation->getId()));
        self::assertResponseIsSuccessful();
        $form = $crawler->filter(sprintf('form[action="/offre/formations/%d"]', $formation->getId()))->form();
        $values = $form->getPhpValues();
        $values['annees'][$annee['nid']] = ['ouvert' => '1', 'capacite' => '200', 'plateformes' => ['']];
        $values['parcours'][OffreFormation::MONO]['ouverture'] = 'ouvert';
        $this->client->request('POST', $form->getUri(), $values);
        self::assertResponseRedirects(sprintf('/offre/formations/%d', $formation->getId()));

        $this->em->clear();
        $after = static::getContainer()->get(OffreFormation::class)->describe($this->formation('Licence Informatique (démo)'));
        $first = $after['parcours'][0]['annees'][0];
        self::assertSame(200, $first['capacite']);
        self::assertFalse($first['plateformes'][0]['active'], 'Parcoursup décoché pour cette année');
        self::assertSame(200 + 150 + 120, $after['capacite']);
    }

    public function testSharedYearIsEnteredOnceAndCountedOnce(): void
    {
        $formation = $this->formation('Licence Sciences & Technologies (démo avec parcours)');
        $offre = static::getContainer()->get(OffreFormation::class)->describe($formation);

        // l'année du portail commun appartient aux trois parcours terminaux
        $shared = array_values(array_filter($offre['parcours'][0]['annees'], static fn (array $a) => $a['partage'] > 0))[0];
        self::assertSame(3, $shared['partage']);
        foreach ($offre['parcours'] as $p) {
            self::assertContains($shared['nid'], array_column($p['annees'], 'nid'));
        }

        $crawler = $this->client->request('GET', sprintf('/offre/formations/%d', $formation->getId()));
        self::assertCount(1, $crawler->filter(sprintf('input[name="annees[%s][capacite]"]', $shared['nid'])));
        self::assertCount(3, $crawler->filter(sprintf('[data-offre-annee="%s"]', $shared['nid'])));
        // parcours non ouvert : ses années ne comptent pas dans la capacité
        self::assertContains('non_ouvert', array_column($offre['parcours'], 'ouverture'));
    }

    public function testFormationCreatedInConsolidationOpensItsOfferSettings(): void
    {
        $this->client->request('POST', '/formations', [
            'name' => 'Licence en préparation', 'typeDiplome' => 'L', 'duree' => 3, 'anneeUniversitaire' => '2026-2027',
            'phase' => 'consolidation', 'composante' => 'UFR Sciences', 'retour' => 'offre',
        ]);

        $formation = $this->formation('Licence en préparation');
        self::assertSame(FormationPhase::Consolidation, $formation->getPhase());
        self::assertResponseRedirects(sprintf('/offre/formations/%d', $formation->getId()));

        $crawler = $this->client->followRedirect();
        self::assertCount(1, $crawler->filter('[data-consolidation] form[action="/formations/phase/construction"]'));

        $this->client->request('POST', '/formations/phase/construction', ['ids' => [$formation->getId()]]);
        self::assertResponseRedirects('/offre');
    }

    public function testOldConsolidationTabLeadsToTheOffer(): void
    {
        $this->client->request('GET', '/', ['phase' => 'consolidation']);

        self::assertResponseRedirects('/offre');
    }

    public function testDiplomaChoosesItsAdmissionPlatforms(): void
    {
        $type = $this->em->getRepository(TypeDiplome::class)->findOneBy(['key' => 'M']);
        $path = sprintf('/diplomes/%d/versions/%d/admission', $type->getId(), $type->latestVersion()->getId());

        $crawler = $this->client->request('GET', $path);
        self::assertResponseIsSuccessful();
        self::assertSame(['Parcoursup', 'Mon Master', 'eCandidat'], $crawler->filter('[data-plateforme]')->each(static fn ($n) => $n->attr('data-plateforme')));

        $values = $crawler->filter('form#wizard-form')->form()->getPhpValues();
        foreach ($values['admission_config']['plateformes'] as $i => $row) {
            unset($values['admission_config']['plateformes'][$i]['enabled']);
            if ('eCandidat' === $row['key']) {
                $values['admission_config']['plateformes'][$i]['enabled'] = '1';
                $values['admission_config']['plateformes'][$i]['annees'] = '1, 2';
            }
        }
        $this->client->request('POST', $path, $values);
        self::assertResponseRedirects($path);

        $this->em->clear();
        $type = $this->em->getRepository(TypeDiplome::class)->findOneBy(['key' => 'M']);
        self::assertSame(['eCandidat' => [1, 2]], $type->latestVersion()->config()->admission);
    }

    private function formation(string $name): Formation
    {
        return $this->em->getRepository(Formation::class)->findOneBy(['name' => $name]);
    }

    /** @return list<string> */
    private function names(\Symfony\Component\DomCrawler\Crawler $crawler): array
    {
        return $crawler->filter('[data-offre-formation] td:first-child .font-semibold')->each(static fn ($n) => trim(preg_replace('/\s+/', ' ', $n->text())));
    }
}
