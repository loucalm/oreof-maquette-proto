<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\FicheMatiere;
use App\Entity\Formation;
use App\FicheMatiere\EcValueResolver;
use App\Maquette\Doc\TreeNode;
use App\Maquette\Maquette;
use App\Tests\Support\FormationFactory;
use App\Tests\Support\ResetsDatabase;
use App\Tests\Support\SameOriginCsrf;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Panneau d'un EC : fiche matière, sources des valeurs, imposition et type d'EC (spec §6.5, §7.2 bis, §8.2). */
final class EcPanelFicheTest extends WebTestCase
{
    use ResetsDatabase;
    use SameOriginCsrf;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->resetDatabase();
        $this->sameOrigin($this->client);
    }

    public function testFicheChoicesAreAvailableOnes(): void
    {
        ['formation' => $formation, 'parcours' => $parcours, 'nid' => $nid] = $this->fixture();
        [, $other] = $this->parcours($formation);
        $fiche = $this->fiche($formation, $other->nid, 'Fiche du parcours frère');
        $allowed = $this->fiche($formation, $parcours->nid, 'Fiche du parcours');
        $this->em->flush();

        $crawler = $this->get($formation, $nid);
        $values = $crawler->filter('select[name="attr_ficheMatiere"] option')->each(static fn ($o) => $o->attr('value'));

        self::assertContains((string) $allowed->getId(), $values);
        self::assertNotContains((string) $fiche->getId(), $values);
    }

    public function testCreateFicheFromPanel(): void
    {
        ['formation' => $formation, 'parcours' => $parcours, 'nid' => $nid] = $this->fixture();

        $this->save($formation, $nid, ['attr_ficheMatiere_autre' => 'Nouvelle fiche']);

        $fiche = $this->em->getRepository(FicheMatiere::class)->findOneBy(['libelle' => 'Nouvelle fiche']);
        self::assertInstanceOf(FicheMatiere::class, $fiche);
        self::assertSame($formation->getId(), $fiche->getFormation()?->getId());
        self::assertSame($parcours->nid, $fiche->getParcoursNid());
        self::assertSame($fiche->getId(), $this->node($formation, $nid)->getAttribute('ficheMatiere'));
    }

    public function testSourceSelectorSwitchesToEcAndPrefills(): void
    {
        ['formation' => $formation, 'nid' => $nid] = $this->fixture();
        $fiche = $this->fiche($formation, $this->firstParcours($formation)->nid, 'Algo', ['ects' => 4]);
        $this->link($formation, $nid, $fiche);

        $crawler = $this->get($formation, $nid);
        self::assertSame('fiche', $crawler->filter('select[name="sources[ects]"] option[selected]')->attr('value'));
        $disabled = $crawler->filter('fieldset[data-value-source="ects"]');
        self::assertCount(1, $disabled);
        self::assertNotNull($disabled->attr('disabled'));
        self::assertSame('4', $disabled->filter('input[name="attr_ects"]')->attr('value'));

        $this->save($formation, $nid, ['sources' => ['ects' => 'ec'], 'attr_ects' => '5']);

        $node = $this->node($formation, $nid);
        self::assertSame(5.0, $node->getAttribute('ects'));
        self::assertSame(['ects' => 'ec'], $node->getAttribute('sources'));
    }

    public function testSavingWithFicheSourceKeepsOwnValue(): void
    {
        ['formation' => $formation, 'nid' => $nid] = $this->fixture();
        $fiche = $this->fiche($formation, $this->firstParcours($formation)->nid, 'Algo', ['ects' => 4]);
        $this->em->flush();
        $doc = $this->maquette()->open($formation);
        $doc->node($nid)->setAttribute('ects', 5.0)->setAttribute('ficheMatiere', $fiche->getId())
            ->setAttribute('sources', ['ects' => 'fiche']);
        $this->maquette()->save($formation, $doc);

        $this->save($formation, $nid, ['sources' => ['ects' => 'fiche']]);

        self::assertSame(5.0, $this->node($formation, $nid)->getAttribute('ects'));
    }

    public function testImposedSelectorDisabled(): void
    {
        ['formation' => $formation, 'nid' => $nid] = $this->fixture();
        $fiche = $this->fiche($formation, $this->firstParcours($formation)->nid, 'Algo', ['ects' => 6]);
        $fiche->setImposed('ects', true);
        $this->link($formation, $nid, $fiche);

        $crawler = $this->get($formation, $nid);
        $selector = $crawler->filter('select[name="sources[ects]"]');
        self::assertNotNull($selector->attr('disabled'));
        self::assertStringContainsString('Imposé par la fiche', $crawler->filter('[data-value-source="ects"]')->text());

        $this->save($formation, $nid, ['sources' => ['ects' => 'ec'], 'attr_ects' => '3']);

        $node = $this->node($formation, $nid);
        self::assertNull($node->getAttribute('ects'));
        self::assertSame(6.0, (float) $this->resolver()->resolve($node, 'ects')->value);
    }

    public function testTypeEcFromFiche(): void
    {
        ['formation' => $formation, 'nid' => $nid] = $this->fixture();
        $fiche = $this->fiche($formation, $this->firstParcours($formation)->nid, 'Stage', [], ['typeEc' => 'stage']);
        $this->link($formation, $nid, $fiche);

        $crawler = $this->get($formation, $nid);
        $type = $crawler->filter('fieldset[data-ec-type-locked] select[name="attr_natureElement"]');
        self::assertCount(1, $type);
        self::assertSame('stage', $type->filter('option[selected]')->attr('value'));

        $this->save($formation, $nid, ['attr_natureElement' => 'ec']);

        self::assertSame('stage', $this->node($formation, $nid)->getAttribute('natureElement'));
    }

    public function testTypeEcAbsentInVersionKeepsOwn(): void
    {
        ['formation' => $formation, 'nid' => $nid] = $this->fixture();
        $fiche = $this->fiche($formation, $this->firstParcours($formation)->nid, 'Algo', [], ['typeEc' => 'hors_version']);
        $this->link($formation, $nid, $fiche);

        $crawler = $this->get($formation, $nid);
        self::assertCount(0, $crawler->filter('fieldset[data-ec-type-locked]'));

        $this->save($formation, $nid, ['attr_natureElement' => 'ec']);

        self::assertSame('ec', $this->node($formation, $nid)->getAttribute('natureElement'));
    }

    public function testCurrentFicheShownEvenIfNotAuthorised(): void
    {
        ['formation' => $formation, 'nid' => $nid] = $this->fixture();
        [, $other] = $this->parcours($formation);
        $fiche = $this->fiche($formation, $other->nid, 'Fiche interdite');
        $this->link($formation, $nid, $fiche);

        $crawler = $this->get($formation, $nid);
        $option = $crawler->filter(sprintf('select[name="attr_ficheMatiere"] option[value="%d"]', $fiche->getId()));

        self::assertCount(1, $option);
        self::assertStringContainsString('non autorisée', $option->text());
        self::assertCount(1, $crawler->filter('a[data-fiche-link]'));
    }

    public function testPinnedValueShowsKeptMessage(): void
    {
        ['formation' => $formation, 'nid' => $nid] = $this->fixture();
        $fiche = $this->fiche($formation, $this->firstParcours($formation)->nid, 'Algo', ['ects' => 4]);
        $this->em->flush();
        $doc = $this->maquette()->open($formation);
        $doc->node($nid)->setAttribute('ects', 3.0);
        $this->maquette()->save($formation, $doc);
        // changement de contexte : la valeur propre est figée lors du choix de la fiche
        $this->save($formation, $nid, ['attr_ficheMatiere' => (string) $fiche->getId(), 'attr_ects' => '3']);

        $crawler = $this->get($formation, $nid);
        $kept = $crawler->filter('[data-pinned-value="ects"]');
        self::assertCount(1, $kept);
        self::assertStringContainsString('Valeur conservée : spécifique à cet EC', $kept->text());
        self::assertStringContainsString('Reprendre la fiche', $kept->text());

        $this->save($formation, $nid, ['sources' => ['ects' => 'fiche']]);
        self::assertNull($this->node($formation, $nid)->getAttribute('sourcesFigees'));
    }

    /** @return array{formation: Formation, parcours: TreeNode, nid: string} */
    private function fixture(): array
    {
        $formation = FormationFactory::create(static::getContainer())->fromType('L', 3, avecParcours: true, withLeaves: true);
        $parcours = $this->firstParcours($formation);
        $ec = $this->ecsOf($parcours)[0];

        return ['formation' => $formation, 'parcours' => $parcours, 'nid' => $ec->nid];
    }

    /** @return list<TreeNode> */
    private function parcours(Formation $formation): array
    {
        $doc = $this->maquette()->open($formation);
        $doc->duplicateNode($doc->parcoursNodes()[0]);
        $this->maquette()->save($formation, $doc);

        return $doc->parcoursNodes();
    }

    private function firstParcours(Formation $formation): TreeNode
    {
        return $this->maquette()->open($formation)->parcoursNodes()[0];
    }

    /** @return list<TreeNode> */
    private function ecsOf(TreeNode $parcours): array
    {
        $result = [];
        $walk = static function (TreeNode $node) use (&$walk, &$result): void {
            foreach ($node->getChildren() as $child) {
                if ($child->getType()->isElementLevel()) {
                    $result[] = $child;
                }
                $walk($child);
            }
        };
        $walk($parcours);

        return $result;
    }

    /**
     * @param array<string, mixed> $defaults
     * @param array<string, mixed> $data
     */
    private function fiche(Formation $formation, string $parcoursNid, string $libelle, array $defaults = [], array $data = []): FicheMatiere
    {
        $fiche = (new FicheMatiere())->setFormation($formation)->setParcoursNid($parcoursNid)
            ->setData(['libelle' => $libelle] + $data)->setDefaults($defaults);
        $this->em->persist($fiche);

        return $fiche;
    }

    private function link(Formation $formation, string $nid, FicheMatiere $fiche): void
    {
        $this->em->flush();
        $doc = $this->maquette()->open($formation);
        $doc->node($nid)->setAttribute('ficheMatiere', $fiche->getId());
        $this->maquette()->save($formation, $doc);
    }

    private function get(Formation $formation, string $nid): \Symfony\Component\DomCrawler\Crawler
    {
        $crawler = $this->client->request('GET', sprintf('/formations/%d/nodes/%s', $formation->getId(), $nid));
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    /** @param array<string, mixed> $extra */
    private function save(Formation $formation, string $nid, array $extra = []): void
    {
        $this->client->request('POST', sprintf('/formations/%d/nodes/%s', $formation->getId(), $nid), [
            'label' => 'EC de test',
            'code' => 'EC1',
        ] + $extra);
        $status = $this->client->getResponse()->getStatusCode();
        self::assertGreaterThanOrEqual(200, $status);
        self::assertLessThan(400, $status);
    }

    private function node(Formation $formation, string $nid): TreeNode
    {
        $this->em->clear();
        $fresh = $this->em->find(Formation::class, $formation->getId());
        $node = $this->maquette()->open($fresh)->node($nid);
        self::assertInstanceOf(TreeNode::class, $node);

        return $node;
    }

    private function maquette(): Maquette
    {
        return static::getContainer()->get(Maquette::class);
    }

    private function resolver(): EcValueResolver
    {
        return static::getContainer()->get(EcValueResolver::class);
    }
}
