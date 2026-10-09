<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\StructureTemplate;
use App\Maquette\Maquette;
use App\Maquette\TemplateSummary;
use App\Tests\Support\FormationFactory;
use App\Tests\Support\ResetsDatabase;
use App\Twig\MaquetteExtension;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Retour externe, lot B : lecture générique d'un template (cardinalités, imposé
 * ou exemple) et format BCC propre au BUT (référentiel national en lecture seule).
 */
final class TemplateAndBccFormatTest extends WebTestCase
{
    use ResetsDatabase;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->resetDatabase();
    }

    private function template(string $key): StructureTemplate
    {
        return $this->em->getRepository(StructureTemplate::class)->findOneBy(['key' => $key]);
    }

    public function testSummarySeparatesImposedCountsFromExamples(): void
    {
        $rows = static::getContainer()->get(TemplateSummary::class)->rows($this->template('but'));
        $byPair = [];
        foreach ($rows as $r) {
            $byPair[$r['child'].' par '.$r['parent']] = $r;
        }

        self::assertSame(2, $byPair['Semestre par Année']['max']);
        self::assertTrue($byPair['Semestre par Année']['fixed']);
        self::assertFalse($byPair['EC par UE']['fixed']);
        self::assertSame(3, $byPair['EC par UE']['min']);
        self::assertContains('SAÉ 1', $byPair['EC par UE']['examples']);
    }

    public function testTemplatePageShowsGenericModelAndAddActions(): void
    {
        $this->client->request('GET', '/administration/templates/'.$this->template('but')->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Modèle générique');
        self::assertSelectorExists('details.template-add > summary.template-add-btn');
    }

    public function testButFormationShowsTheNationalReferentialReadOnly(): void
    {
        $formation = FormationFactory::create(static::getContainer())->fromTemplate('but');

        $this->client->request('GET', '/formations/'.$formation->getId().'/bcc');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'AC11.01');
        self::assertSelectorNotExists('form[action$="/bcc/blocs"]');

        // pas de bloc saisi en BUT
        $this->client->request('POST', '/formations/'.$formation->getId().'/bcc/blocs');
        $this->em->clear();
        $fresh = $this->em->find($formation::class, $formation->getId());
        self::assertSame([], static::getContainer()->get(Maquette::class)->open($fresh)->competenceBlocs());
    }

    public function testButBccStatusIsOkEvenWithParcours(): void
    {
        $template = $this->template('master_parcours')->setBccFormat(StructureTemplate::BCC_BUT);
        $this->em->flush();
        $formation = FormationFactory::create(static::getContainer())->fromTemplate('master_parcours');

        foreach ($formation->getStats()['parcours'] ?? [] as $p) {
            self::assertSame('ok', $p['bcc']);
        }
        self::assertNotEmpty($formation->getStats()['parcours'] ?? []);
    }

    public function testButEcIsOfferedTheAcsOfItsYear(): void
    {
        $formation = FormationFactory::create(static::getContainer())->fromTemplate('but');
        $ec = FormationFactory::create(static::getContainer())->first(static::getContainer()->get(Maquette::class)->open($formation), 'ec');

        $groups = static::getContainer()->get(MaquetteExtension::class)->bccCompetences($ec);

        self::assertNotEmpty($groups);
        foreach ($groups as $g) {
            self::assertStringEndsWith('BUT1', $g['bloc']);
        }
        self::assertSame('AC11.01', $groups[0]['items'][0]['code']);
    }

    public function testLicenceKeepsEditableBlocs(): void
    {
        $formation = FormationFactory::create(static::getContainer())->fromTemplate('licence_lmd');

        $this->client->request('GET', '/formations/'.$formation->getId().'/bcc');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form[action$="/bcc/blocs"]');
    }
}
