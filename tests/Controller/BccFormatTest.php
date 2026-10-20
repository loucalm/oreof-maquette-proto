<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Formation;
use App\Maquette\Doc\TreeNode;
use App\Maquette\Maquette;
use App\Tests\Support\FormationFactory;
use App\Tests\Support\ResetsDatabase;
use App\Twig\MaquetteExtension;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Format des BCC : une formation qui porte un référentiel national (compétence › niveau › apprentissage
 * critique) le montre en lecture seule, sans blocs à saisir ; les noms des niveaux viennent de la version
 * du type de diplôme. Sans référentiel, les blocs de compétences restent saisissables, quel que soit le type.
 */
final class BccFormatTest extends WebTestCase
{
    use ResetsDatabase;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->resetDatabase();
    }

    private function factory(): FormationFactory
    {
        return FormationFactory::create(static::getContainer());
    }

    /** Formation d'un type à trois niveaux de BCC qui porte un référentiel national de test. */
    private function nationalFormation(string $typeKey = 'BUT', int $duration = 3, bool $avecParcours = false): Formation
    {
        $formation = $this->factory()->fromType($typeKey, $duration, avecParcours: $avecParcours);
        $niveau = static fn (int $n) => ['ordre' => $n, 'annee' => 'BUT'.$n, 'libelle' => "Niveau $n", 'acs' => [['code' => sprintf('AC%d1.01', $n), 'libelle' => "Apprentissage $n"]]];
        $maquette = static::getContainer()->get(Maquette::class);
        $doc = $maquette->open($formation);
        $doc->setFormationParam('bccReferentiel', ['competences' => [['numero' => 1, 'nomCourt' => 'Réaliser', 'libelle' => 'Réaliser une application', 'niveaux' => [$niveau(1), $niveau(2), $niveau(3)]]]]);
        $maquette->save($formation, $doc);

        return $formation;
    }

    public function testFormationWithNationalReferentialShowsItReadOnly(): void
    {
        $formation = $this->nationalFormation();

        $this->client->request('GET', '/formations/'.$formation->getId().'/bcc');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'AC11.01');
        self::assertSelectorNotExists('form[action$="/bcc/blocs"]');
        // les noms de niveaux viennent de la version du type de diplôme
        self::assertSelectorTextContains('body', 'Compétence › Niveau › Apprentissage critique');

        // aucun bloc ne peut être saisi
        $this->client->request('POST', '/formations/'.$formation->getId().'/bcc/blocs');
        $this->em->clear();
        $fresh = $this->em->find($formation::class, $formation->getId());
        self::assertSame([], static::getContainer()->get(Maquette::class)->open($fresh)->competenceBlocs());
    }

    public function testNationalReferentialBccStatusIsOkEvenWithParcours(): void
    {
        $formation = $this->nationalFormation('L', 3, true);

        self::assertNotEmpty($formation->getStats()['parcours'] ?? []);
        foreach ($formation->getStats()['parcours'] as $p) {
            self::assertSame('ok', $p['bcc']);
        }
    }

    public function testEcIsOfferedTheAcsOfItsYear(): void
    {
        $formation = $this->nationalFormation();
        $maquette = static::getContainer()->get(Maquette::class);
        $doc = $maquette->open($formation);
        $ue = $doc->pedagogicalRoots()[0]->getChildren()[0]->getChildren()[0];
        $ec = $doc->addNode($ue->nid, 'ec', 'Ressource');
        $maquette->save($formation, $doc);

        $groups = static::getContainer()->get(MaquetteExtension::class)->bccCompetences($this->reload($formation, $ec));

        self::assertNotEmpty($groups);
        foreach ($groups as $g) {
            self::assertStringEndsWith('BUT1', $g['bloc']);
        }
        self::assertSame('AC11.01', $groups[0]['items'][0]['ref']);
        self::assertSame('AC11.01', $groups[0]['items'][0]['id']);
    }

    public function testFormationWithoutReferentialKeepsEditableBlocsWhateverItsType(): void
    {
        foreach (['L' => 3, 'BUT' => 3] as $typeKey => $duration) {
            $formation = $this->factory()->fromType($typeKey, $duration);

            $this->client->request('GET', '/formations/'.$formation->getId().'/bcc');

            self::assertResponseIsSuccessful();
            self::assertSelectorExists('form[action$="/bcc/blocs"]');
        }
    }

    private function reload(Formation $formation, TreeNode $node): TreeNode
    {
        $this->em->clear();
        $fresh = $this->em->find(Formation::class, $formation->getId());

        return static::getContainer()->get(Maquette::class)->open($fresh)->node($node->nid);
    }
}
