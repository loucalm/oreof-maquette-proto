<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Diplome\Spec\CanvasEntry;
use App\Diplome\Spec\CanvasSpec;
use App\Diplome\Wizard\WizardStepRegistry;
use App\Diplome\WizardDraft;
use App\Entity\NodeType;
use App\Entity\TypeDiplome;
use App\Enum\FormScope;
use App\Tests\Support\FormationFactory;
use App\Tests\Support\ResetsDatabase;
use App\Tests\Support\SameOriginCsrf;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Étape / onglet « Formulaires » : édition du canevas des fiches formation et parcours. */
final class DiplomeCanvasStepTest extends WebTestCase
{
    use ResetsDatabase;
    use SameOriginCsrf;

    private const STEP = '/diplomes/nouveau/formulaires';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->sameOrigin($this->client);
        $this->resetDatabase();
    }

    public function testFormulairesStepComesAfterStructure(): void
    {
        $keys = static::getContainer()->get(WizardStepRegistry::class)->keys();

        self::assertSame(array_search('structure', $keys, true) + 1, array_search('formulaires', $keys, true));
    }

    public function testCanvasRowsReproduceDefault(): void
    {
        $this->startFromLicence();

        $crawler = $this->client->request('GET', self::STEP);

        self::assertResponseIsSuccessful();
        self::assertSame(['organisation', 'presentation', 'bcc', 'ramification', 'structure'], $crawler->filter('[data-fiche="formation"] [data-entry]')->each(static fn ($n) => $n->attr('data-entry')));
        self::assertSame(['organisation', 'presentation', 'bcc', 'positionnement'], $crawler->filter('[data-fiche="parcours"] [data-entry]')->each(static fn ($n) => $n->attr('data-entry')));
    }

    public function testHideRenameReorderAndAddEntry(): void
    {
        $this->startFromLicence();
        $values = $this->values();
        $rows = &$values['canvas']['formation'];
        $rows[0]['label'] = 'Organisation';
        $rows[1]['hidden'] = '1';
        $rows[] = ['key' => '', 'builtin' => '', 'label' => 'Alternance', 'form' => 'param_presentation_formation', 'position' => '1'];
        foreach ([0 => 0, 1 => 2, 2 => 3, 3 => 4, 4 => 5] as $i => $position) {
            $rows[$i]['position'] = (string) $position;
        }
        unset($rows);

        $this->client->request('POST', self::STEP, $values);

        self::assertResponseRedirects();
        $canvas = $this->draft()->config()->canvas;
        self::assertSame(['organisation', 'alternance', 'presentation', 'bcc', 'ramification', 'structure'], array_map(static fn (CanvasEntry $e) => $e->key, $canvas->formation));
        self::assertTrue($canvas->entry(CanvasSpec::FORMATION, 'presentation')->hidden);
        self::assertSame('Organisation', $canvas->entry(CanvasSpec::FORMATION, 'organisation')->label);
        self::assertSame('param_presentation_formation', $canvas->entry(CanvasSpec::FORMATION, 'alternance')->form);
    }

    public function testBuiltinsCannotBeRemoved(): void
    {
        $this->startFromLicence();
        $values = $this->values();
        unset($values['canvas']['formation'][4]); // structure

        $this->client->request('POST', self::STEP, $values);

        self::assertResponseRedirects();
        self::assertNotNull($this->draft()->config()->canvas->entry(CanvasSpec::FORMATION, 'structure'));
    }

    public function testStructureCannotBeHidden(): void
    {
        $this->startFromLicence();
        $values = $this->values();
        $values['canvas']['formation'][4]['hidden'] = '1';

        $this->client->request('POST', self::STEP, $values);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('ne peut pas être masqué', (string) $this->client->getResponse()->getContent());
    }

    public function testLockedFieldsMustStayVisible(): void
    {
        $this->startFromLicence();
        $values = $this->values();
        $values['canvas']['formation'][0]['hidden'] = '1'; // organisation : nom, domaine, composante, responsable

        $this->client->request('POST', self::STEP, $values);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Nom de la formation', (string) $this->client->getResponse()->getContent());
    }

    public function testNewEntryNeedsALabelAndAForm(): void
    {
        $this->startFromLicence();
        $values = $this->values();
        $values['canvas']['parcours'][] = ['key' => '', 'builtin' => '', 'label' => '', 'form' => '', 'position' => '9'];

        $this->client->request('POST', self::STEP, $values);

        self::assertResponseStatusCodeSame(422);
    }

    public function testFormChoicesFilteredByScope(): void
    {
        $this->startFromLicence();
        $crawler = $this->client->request('GET', self::STEP);

        $values = array_filter($crawler->filter('select[name="canvas[formation][0][form]"] option')->each(static fn ($o) => (string) $o->attr('value')));
        self::assertNotEmpty($values);
        $repo = $this->em->getRepository(NodeType::class);
        foreach ($values as $key) {
            self::assertSame(FormScope::Formation, $repo->findOneBy(['key' => $key])->getScope(), $key);
        }
    }

    public function testEditTabRequiresImpactConfirmation(): void
    {
        $but = $this->em->getRepository(TypeDiplome::class)->findOneBy(['key' => 'BUT']);
        FormationFactory::create(static::getContainer())->fromType('BUT', 3);
        $path = sprintf('/diplomes/%d/versions/%d/formulaires', $but->getId(), $but->latestVersion()->getId());
        $crawler = $this->client->request('GET', $path);
        $values = $crawler->filter('form#wizard-form')->form()->getPhpValues();
        $values['canvas']['formation'][1]['hidden'] = '1';

        $this->client->request('POST', $path, $values);
        self::assertResponseStatusCodeSame(422);

        $this->client->request('POST', $path, $values + ['confirmImpact' => '1']);
        self::assertResponseRedirects($path);
        $this->em->clear();
        $version = $this->em->getRepository(TypeDiplome::class)->findOneBy(['key' => 'BUT'])->latestVersion();
        self::assertTrue($version->config()->canvas->entry(CanvasSpec::FORMATION, 'presentation')->hidden);
    }

    public function testSummaryShowsCanvasDifferences(): void
    {
        $this->startFromLicence();
        $values = $this->values();
        $values['canvas']['formation'][1]['hidden'] = '1';
        $this->client->request('POST', self::STEP, $values);

        $crawler = $this->client->request('GET', '/diplomes/nouveau/recapitulatif');

        self::assertCount(1, $crawler->filter('[data-diff] [data-path="canvas.formation.1.hidden"]'));
    }

    private function startFromLicence(): void
    {
        $licence = $this->em->getRepository(TypeDiplome::class)->findOneBy(['label' => 'Licence']);
        $this->client->request('GET', '/diplomes/nouveau', ['depuis' => $licence->latestVersion()->getId()]);
        $crawler = $this->client->request('GET', '/diplomes/nouveau/informations');
        $this->client->submit($crawler->filter('form#wizard-form')->form(['informations[label]' => 'Deust', 'informations[sigle]' => 'DEUST']));
    }

    /** @return array<string, mixed> valeurs actuelles du formulaire de l'étape */
    private function values(): array
    {
        return $this->client->request('GET', self::STEP)->filter('form#wizard-form')->form()->getPhpValues();
    }

    private function draft(): WizardDraft
    {
        return $this->client->getRequest()->getSession()->get(WizardDraft::SESSION_KEY);
    }
}
