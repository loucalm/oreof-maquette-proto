<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\FicheMatiereController;
use App\Entity\FicheMatiere;
use App\Entity\FicheMatiereMutualisation;
use App\Entity\Formation;
use App\Formulaire\Builtin\BuiltinScreenRegistry;
use App\Maquette\Doc\TreeNode;
use App\Maquette\Maquette;
use App\Repository\FicheMatiereMutualisationRepository;
use App\Tests\Support\FormationFactory;
use App\Tests\Support\ResetsDatabase;
use App\Tests\Support\SameOriginCsrf;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Liste des fiches d'un parcours, création, duplication et suppression. */
final class FicheMatiereIndexTest extends WebTestCase
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

    public function testIndexListsPortedThenMutualised(): void
    {
        $formation = $this->formation();
        $autre = $this->formation('Licence utilisatrice');
        $parcours = $this->firstParcours($formation);
        $autreParcours = $this->firstParcours($autre);
        $portee = $this->fiche($formation, $parcours->nid, 'Algorithmique');
        $mutualisee = $this->fiche($autre, $autreParcours->nid, 'Fiche partagée');
        $this->fiche($autre, $autreParcours->nid, 'Fiche étrangère');
        $this->em->persist(new FicheMatiereMutualisation($mutualisee, $formation, $parcours->nid));
        $this->em->flush();
        $this->linkEc($formation, $parcours, $portee, 'EC algorithmique');

        $crawler = $this->client->request('GET', $this->indexUrl($formation, $parcours->nid));

        self::assertResponseIsSuccessful();
        self::assertSame(['portees', 'orphelines', 'mutualisees'], $crawler->filter('[data-fiche-group]')->each(static fn ($n) => $n->attr('data-fiche-group')));
        $portees = $crawler->filter('[data-fiche-group="portees"] [data-fiche-row]');
        self::assertCount(1, $portees);
        self::assertStringContainsString('Algorithmique', $portees->text());
        self::assertStringContainsString('1 EC', $portees->text());
        $mutualisees = $crawler->filter('[data-fiche-group="mutualisees"] [data-fiche-row]');
        self::assertCount(1, $mutualisees);
        self::assertStringContainsString('Fiche partagée', $mutualisees->text());
        self::assertStringNotContainsString('Fiche étrangère', (string) $this->client->getResponse()->getContent());
    }

    public function testSearchFiltersByLibelle(): void
    {
        $formation = $this->formation();
        $parcours = $this->firstParcours($formation);
        $this->fiche($formation, $parcours->nid, 'Mathématiques');
        $this->fiche($formation, $parcours->nid, 'Histoire');
        $this->em->flush();

        $crawler = $this->client->request('GET', $this->indexUrl($formation, $parcours->nid, ['q' => 'hist']));

        self::assertResponseIsSuccessful();
        $rows = $crawler->filter('[data-fiche-group="portees"] [data-fiche-row]');
        self::assertCount(1, $rows);
        self::assertStringContainsString('Histoire', $rows->text());
        self::assertStringNotContainsString('Mathématiques', $rows->text());
    }

    public function testCreate(): void
    {
        $formation = $this->formation();
        $parcours = $this->firstParcours($formation);

        $this->post($this->indexUrl($formation, $parcours->nid), [
            'parcours' => $parcours->nid,
            'libelle' => 'Nouvelle fiche',
        ]);

        self::assertResponseRedirects();
        $this->em->clear();
        $created = $this->em->getRepository(FicheMatiere::class)->findOneBy(['libelle' => 'Nouvelle fiche']);
        self::assertNotNull($created);
        self::assertSame($formation->getId(), $created->getFormation()->getId());
        self::assertSame($parcours->nid, $created->getParcoursNid());
    }

    public function testDuplicate(): void
    {
        $formation = $this->formation();
        $autre = $this->formation('Licence utilisatrice');
        $parcours = $this->firstParcours($formation);
        $fiche = $this->fiche($formation, $parcours->nid, 'Algèbre');
        $fiche->setDefaults(['ects' => 4])->setImposed('ects', true);
        $this->em->persist(new FicheMatiereMutualisation($fiche, $autre, $this->firstParcours($autre)->nid));
        $this->em->flush();

        $this->post(sprintf('/fiches-matieres/%d/dupliquer?parcours=%s', $fiche->getId(), $parcours->nid));

        self::assertResponseRedirects();
        $this->em->clear();
        $copie = $this->em->getRepository(FicheMatiere::class)->findOneBy(['libelle' => 'Algèbre (copie)']);
        self::assertNotNull($copie);
        self::assertSame($formation->getId(), $copie->getFormation()->getId());
        self::assertSame($parcours->nid, $copie->getParcoursNid());
        self::assertSame(4, $copie->getDefault('ects'));
        self::assertTrue($copie->isImposed('ects'));
        $mutualisations = static::getContainer()->get(FicheMatiereMutualisationRepository::class)->findBy(['fiche' => $copie]);
        self::assertSame([], $mutualisations);
    }

    public function testDeleteRefusedWhenUsed(): void
    {
        $formation = $this->formation();
        $parcours = $this->firstParcours($formation);
        $fiche = $this->fiche($formation, $parcours->nid, 'Fiche utilisée');
        $this->em->flush();
        $this->linkEc($formation, $parcours, $fiche, 'EC protégé');
        $id = $fiche->getId();

        $this->post(sprintf('/fiches-matieres/%d/supprimer?parcours=%s', $id, $parcours->nid));
        $crawler = $this->client->followRedirect();

        self::assertStringContainsString('EC protégé', $crawler->filter('.flash-msg')->text());
        $this->em->clear();
        self::assertNotNull($this->em->find(FicheMatiere::class, $id));
    }

    public function testDeleteWhenUnused(): void
    {
        $formation = $this->formation();
        $parcours = $this->firstParcours($formation);
        $fiche = $this->fiche($formation, $parcours->nid, 'Fiche libre');
        $this->em->flush();
        $id = $fiche->getId();

        $this->post(sprintf('/fiches-matieres/%d/supprimer?parcours=%s', $id, $parcours->nid));

        self::assertResponseRedirects();
        $this->em->clear();
        self::assertNull($this->em->find(FicheMatiere::class, $id));
    }

    public function testOrphanListedAfterParcoursDeletion(): void
    {
        $formation = $this->formation();
        $parcours = $this->firstParcours($formation);
        $fiche = $this->fiche($formation, $parcours->nid, 'Fiche orpheline');
        $this->em->flush();
        $id = $fiche->getId();

        $this->client->request('POST', sprintf('/formations/%d/nodes/%s/delete', $formation->getId(), $parcours->nid));

        $crawler = $this->client->request('GET', $this->indexUrl($formation));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Fiche orpheline', $crawler->filter('[data-fiche-group="orphelines"]')->text());
        $this->em->clear();
        self::assertNotNull($this->em->find(FicheMatiere::class, $id));
    }

    public function testScreenInParcoursSidebar(): void
    {
        $formation = $this->formation();
        $parcours = $this->firstParcours($formation);

        $crawler = $this->client->request('GET', sprintf('/formations/%d/parcours/%s', $formation->getId(), $parcours->nid));

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter(sprintf('a.nav-section[href="%s"]', $this->indexUrl($formation, $parcours->nid))));
        $screen = static::getContainer()->get(BuiltinScreenRegistry::class)->get('parcours', 'fiches_matieres');
        self::assertNotNull($screen);
        self::assertFalse($screen->hideable());
    }

    public function testScreenInMonoParcoursSidebar(): void
    {
        $formation = FormationFactory::create(static::getContainer())->fromType('L', 3, 'Licence mono', withLeaves: true);

        $crawler = $this->client->request('GET', sprintf('/formations/%d', $formation->getId()));

        self::assertResponseIsSuccessful();
        $url = sprintf('/formations/%d/fiches-matieres', $formation->getId());
        self::assertGreaterThan(0, $crawler->filter(sprintf('a[href="%s"]', $url))->count());
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
    }

    public function testForeignParcoursIs404(): void
    {
        $formation = $this->formation();
        $autre = $this->formation('Autre licence');
        $nidAutre = $this->firstParcours($autre)->nid;
        $sien = $this->firstParcours($formation)->nid;

        $this->client->request('GET', $this->indexUrl($formation, $nidAutre === $sien ? 'inexistant' : $nidAutre));
        self::assertResponseStatusCodeSame(404);

        $this->client->request('POST', sprintf('/formations/%d/fiches-matieres', $formation->getId()), [
            'libelle' => 'Intruse', 'parcours' => 'inexistant', '_csrf_token' => $this->csrfToken('fiche_matiere'),
        ]);
        self::assertResponseStatusCodeSame(404);
        self::assertSame(0, $this->em->getRepository(FicheMatiere::class)->count(['libelle' => 'Intruse']));
    }

    private function formation(string $name = 'Licence fiches'): Formation
    {
        return FormationFactory::create(static::getContainer())->fromType('L', 3, $name, avecParcours: true, withLeaves: true);
    }

    private function fiche(Formation $formation, string $parcoursNid, string $libelle): FicheMatiere
    {
        $fiche = (new FicheMatiere())->setFormation($formation)->setParcoursNid($parcoursNid)
            ->setData(['libelle' => $libelle]);
        $this->em->persist($fiche);

        return $fiche;
    }

    private function firstParcours(Formation $formation): TreeNode
    {
        return static::getContainer()->get(Maquette::class)->open($formation)->parcoursNodes()[0];
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

    private function linkEc(Formation $formation, TreeNode $parcours, FicheMatiere $fiche, string $label): void
    {
        $maquette = static::getContainer()->get(Maquette::class);
        $doc = $maquette->open($formation);
        $ec = $this->ecsOf($parcours)[0];
        $ec->setLabel($label)->setAttribute('ficheMatiere', $fiche->getId());
        $maquette->save($formation, $doc);
    }

    private function indexUrl(Formation $formation, ?string $nid = null, array $query = []): string
    {
        if (null !== $nid) {
            $query = ['parcours' => $nid] + $query;
        }

        return sprintf('/formations/%d/fiches-matieres', $formation->getId()).($query ? '?'.http_build_query($query) : '');
    }

    /** @param array<string, string> $params */
    private function post(string $url, array $params = []): void
    {
        $this->client->request('POST', $url, $params + [
            '_csrf_token' => $this->csrfToken(FicheMatiereController::CSRF_ID),
        ]);
    }
}
