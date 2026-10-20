<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Maquette\Doc\TreeNode;
use App\Maquette\Maquette;
use App\Tests\Support\FormationFactory;
use App\Tests\Support\ResetsDatabase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Le rendu des champs (panneau d'un nœud, pages paramètre) passe par un gabarit par type de champ. */
final class GenericFieldRenderingTest extends WebTestCase
{
    use ResetsDatabase;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->resetDatabase();
    }

    private function panel(string $typeKey, string $template = 'licence_lmd'): \Symfony\Component\DomCrawler\Crawler
    {
        $formation = FormationFactory::create(static::getContainer())->fromTemplate($template);
        $this->em->clear();
        $fresh = $this->em->find($formation::class, $formation->getId());
        $doc = static::getContainer()->get(Maquette::class)->open($fresh);
        $node = current(array_filter($doc->allNodes(), static fn (TreeNode $n) => $n->typeKey === $typeKey));

        return $this->client->request('GET', sprintf('/formations/%d/nodes/%s', $formation->getId(), $node->nid));
    }

    public function testEcPanelRendersEveryFieldTypeThroughItsTemplate(): void
    {
        $crawler = $this->panel('ec');

        self::assertCount(1, $crawler->filter('input[type=number][name=attr_ects]'));
        self::assertCount(1, $crawler->filter('select[name=attr_ecType]'));
        self::assertCount(1, $crawler->filter('select[name=attr_ficheMatiere]'));
    }

    public function testEcPanelMcccAndHoursBlocks(): void
    {
        $crawler = $this->panel('ec');

        self::assertGreaterThan(0, $crawler->filter('input[type=radio][name="attr_mccc[type]"]')->count());
        self::assertCount(1, $crawler->filter('input[name="attr_hours[none]"]'));
    }

    public function testUePanelHasTypePlaceholderFromFieldData(): void
    {
        $crawler = $this->panel('ue');

        self::assertSame("Choisir un type d'UE", trim($crawler->filter('select[name=attr_ueType] option[value=""]')->text()));
        self::assertStringContainsString('ECTS (UE)', $crawler->filter('#node-form')->text());
        self::assertStringContainsString('(UE)', $crawler->filter('#node-form')->text());
        self::assertStringContainsString('Transformer en bloc de choix', $crawler->text());
        self::assertStringContainsString('n\'assemble aucune alternative', $crawler->text());
    }

    public function testFormationParamPageRendersTextareaWithPrefixedName(): void
    {
        $formation = FormationFactory::create(static::getContainer())->fromTemplate('licence_lmd');
        $crawler = $this->client->request('GET', '/formations/'.$formation->getId().'/parametre/presentation');

        self::assertCount(1, $crawler->filter('textarea[name="p[objectif]"]'));
    }

    public function testFormationParamPageRendersRadioAndChoiceWithPrefixedNames(): void
    {
        $formation = FormationFactory::create(static::getContainer())->fromTemplate('licence_lmd');
        $crawler = $this->client->request('GET', '/formations/'.$formation->getId().'/parametre/organisation');

        self::assertCount(2, $crawler->filter('input[type=radio][name="p[rncp]"]'));
        self::assertCount(1, $crawler->filter('select[name="p[mention]"]'));
    }

    public function testReadOnlyViewRendersChoiceLabelsThroughViewTemplates(): void
    {
        $formation = FormationFactory::create(static::getContainer())->fromTemplate('licence_lmd');
        $formation->setParametre('organisation', ['rncp' => 'oui']);
        $this->em->flush();
        $crawler = $this->client->request('GET', '/formations/'.$formation->getId().'/voir');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Oui', $crawler->filter('[data-node="param:organisation"]')->text());
    }

    public function testEctsLabelUsesNonElidingTypeWording(): void
    {
        $crawler = $this->panel('parcours', 'master_parcours');
        self::assertStringContainsString('ECTS (Parcours)', $crawler->filter('#node-form')->text());
        self::assertStringNotContainsString('Transformer en bloc de choix', $crawler->text());
    }
}
