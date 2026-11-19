<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\FicheMatiereController;
use App\Entity\FicheMatiere;
use App\Entity\Formation;
use App\FicheMatiere\FicheMatiereAccess;
use App\Maquette\Maquette;
use App\Repository\TypeDiplomeRepository;
use App\Tests\Support\FormationFactory;
use App\Tests\Support\ResetsDatabase;
use App\Tests\Support\SameOriginCsrf;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Fiches matière hors diplôme : liste et création dans l'administration, disponibilité dans toutes les formations. */
final class FicheMatiereHorsDiplomeTest extends WebTestCase
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

    public function testAdminListsAndCreates(): void
    {
        $types = static::getContainer()->get(TypeDiplomeRepository::class);
        $licence = $types->findOneByKey('L');
        $but = $types->findOneByKey('BUT');
        $archive = $types->findOneByKey('M2E');
        $archive->setArchived(true);
        $this->em->flush();

        $this->fiche('Fiche toutes formations');
        $used = $this->fiche('Fiche Licence')->setTypeDiplome($licence);
        $this->em->flush();
        $formation = FormationFactory::create(static::getContainer())->fromType('L', 3, 'Licence utilisatrice', avecParcours: true, withLeaves: true);
        $this->linkFiche($formation, $used);

        $crawler = $this->client->request('GET', '/administration/fiches-matieres');

        self::assertResponseIsSuccessful();
        $rows = $crawler->filter('[data-fiche-row]');
        self::assertCount(2, $rows);
        $text = implode(' ', $rows->each(static fn ($node) => $node->text()));
        self::assertStringContainsString('Fiche toutes formations', $text);
        self::assertStringContainsString('Tous', $text);
        self::assertStringContainsString('Fiche Licence', $text);
        self::assertStringContainsString('1 EC', $text);
        self::assertCount(1, $crawler->filter(sprintf('[data-fiche-row] a[href="%s"]', $this->url($used))));
        $options = $crawler->filter('select[name="typeDiplome"] option')->each(static fn ($node) => $node->attr('value'));
        self::assertContains((string) $but->getId(), $options);
        self::assertNotContains((string) $archive->getId(), $options, 'Le type archivé ne doit pas être proposé.');
        self::assertStringNotContainsString('Master M2E', $crawler->filter('form[method="post"]')->text());

        $this->post(['libelle' => 'Fiche créée']);
        $created = $this->one('Fiche créée');
        self::assertResponseRedirects($this->url($created));
        self::assertTrue($created->isHorsDiplome());
        self::assertNull($created->getFormation());
        self::assertNull($created->getTypeDiplome());

        $this->post(['libelle' => 'Fiche créée avec type', 'typeDiplome' => $but->getId()]);
        $typed = $this->one('Fiche créée avec type');
        self::assertResponseRedirects($this->url($typed));
        self::assertSame($but->getId(), $typed->getTypeDiplome()->getId());

        $before = $this->em->getRepository(FicheMatiere::class)->count([]);
        $this->post(['libelle' => '']);
        $this->post(['libelle' => str_repeat('x', 251)]);
        self::assertSame($before, $this->em->getRepository(FicheMatiere::class)->count([]));
    }

    public function testResponsableRedirected(): void
    {
        $this->client->request('POST', '/role/responsable');
        self::assertResponseRedirects('/');

        $this->client->request('GET', '/administration/fiches-matieres');
        self::assertResponseRedirects('/');

        $this->post(['libelle' => 'Fiche intruse']);
        self::assertResponseRedirects('/');
        self::assertSame(0, $this->em->getRepository(FicheMatiere::class)->count(['libelle' => 'Fiche intruse']));
    }

    public function testHorsDiplomeAvailableEverywhere(): void
    {
        $fiche = $this->fiche('Fiche hors diplôme');
        $this->em->flush();
        $access = static::getContainer()->get(FicheMatiereAccess::class);
        $maquette = static::getContainer()->get(Maquette::class);

        $formations = $this->em->getRepository(Formation::class)->findAll();
        self::assertNotEmpty($formations);
        foreach ($formations as $formation) {
            $doc = $maquette->open($formation);
            $ec = null;
            foreach ($doc->allNodes() as $node) {
                if ($node->getType()->isElementLevel()) {
                    $ec = $node;
                    break;
                }
            }
            if (null === $ec) {
                $ue = null;
                foreach ($doc->allNodes() as $node) {
                    if ('ue' === $node->typeKey) {
                        $ue = $node;
                        break;
                    }
                }
                self::assertNotNull($ue, 'Aucune UE dans '.$formation->getName());
                $ec = $doc->addNode($ue->nid, 'ec', 'EC de test');
                $maquette->save($formation, $doc);
            }

            self::assertContains($fiche, $access->available($ec), $formation->getName());
        }
    }

    private function fiche(string $libelle): FicheMatiere
    {
        $fiche = (new FicheMatiere())->setHorsDiplome(true)->setData(['libelle' => $libelle]);
        $this->em->persist($fiche);

        return $fiche;
    }

    private function one(string $libelle): FicheMatiere
    {
        $fiche = $this->em->getRepository(FicheMatiere::class)->findOneBy(['libelle' => $libelle]);
        self::assertNotNull($fiche);

        return $fiche;
    }

    private function url(FicheMatiere $fiche): string
    {
        return static::getContainer()->get('router')->generate('fiche_matiere_edit', ['id' => $fiche->getId()]);
    }

    private function linkFiche(Formation $formation, FicheMatiere $fiche): void
    {
        $maquette = static::getContainer()->get(Maquette::class);
        $doc = $maquette->open($formation);
        foreach ($doc->allNodes() as $node) {
            if ($node->getType()->isElementLevel()) {
                $node->setAttribute('ficheMatiere', $fiche->getId());
                break;
            }
        }
        $maquette->save($formation, $doc);
    }

    /** @param array<string, mixed> $params */
    private function post(array $params): void
    {
        $this->client->request('POST', '/administration/fiches-matieres', $params + [
            '_csrf_token' => $this->csrfToken(FicheMatiereController::CSRF_ID),
        ]);
    }
}
