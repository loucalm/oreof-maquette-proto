<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\FicheMatiere;
use App\Entity\Formation;
use App\Maquette\Doc\TreeNode;
use App\Maquette\Maquette;
use App\Tests\Support\FormationFactory;
use App\Tests\Support\ResetsDatabase;
use App\Tests\Support\SameOriginCsrf;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Page d'une fiche matière : onglets, enregistrement par onglet, impacts d'imposition, bandeau d'usage. */
final class FicheMatiereEditTest extends WebTestCase
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

    public function testEditShowsTabs(): void
    {
        $formation = $this->formation();
        $fiche = $this->fiche($formation, $this->firstParcours($formation)->nid);
        $this->em->flush();

        $crawler = $this->client->request('GET', $this->url($fiche));

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['identite', 'mutualisation', 'presentation', 'competences', 'volume_horaire', 'mccc'],
            $crawler->filter('[data-fiche-tab]')->each(static fn ($n) => $n->attr('data-fiche-tab')),
        );

        $hors = $this->horsDiplome();
        $crawler = $this->client->request('GET', $this->url($hors));

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['identite', 'mutualisation', 'presentation', 'volume_horaire', 'mccc'],
            $crawler->filter('[data-fiche-tab]')->each(static fn ($n) => $n->attr('data-fiche-tab')),
        );
    }

    public function testSaveRoutesValues(): void
    {
        $formation = $this->formation();
        $fiche = $this->fiche($formation, $this->firstParcours($formation)->nid)
            ->setData(['libelle' => 'Algorithmique', 'description' => 'Programme détaillé']);
        $this->em->flush();

        $out = $this->post($fiche, 'mccc', ['ects' => '3', 'ectsImposes' => 'oui']);

        self::assertSame([], $out['impacts']);
        $saved = $this->reload($fiche);
        self::assertEquals(3, $saved->getDefault('ects'));
        self::assertTrue($saved->isImposed('ects'));
        self::assertSame(['libelle' => 'Algorithmique', 'description' => 'Programme détaillé'], $saved->getData());
    }

    public function testImposingWithImpactsAsksConfirmation(): void
    {
        $formation = $this->formation();
        $parcours = $this->firstParcours($formation);
        $fiche = $this->fiche($formation, $parcours->nid)->setDefault('ects', 6);
        $this->em->flush();

        $maquette = static::getContainer()->get(Maquette::class);
        $doc = $maquette->open($formation);
        $ec = $this->ecsOf($parcours)[0];
        $ec->setAttribute('ficheMatiere', $fiche->getId())
            ->setAttribute('sources', ['ects' => 'ec'])
            ->setAttribute('ects', 3);
        $maquette->save($formation, $doc);

        $out = $this->post($fiche, 'mccc', ['ects' => '6', 'ectsImposes' => 'oui']);

        self::assertNotEmpty($out['impacts']);
        self::assertSame('ects', $out['impacts'][0]['key']);
        self::assertFalse($this->reload($fiche)->isImposed('ects'));
        self::assertSame(3, $this->ecValue($formation, $ec->nid, 'ects'));

        $out = $this->post($fiche, 'mccc', ['ects' => '6', 'ectsImposes' => 'oui'], ['confirmImpact' => '1']);

        self::assertSame([], $out['impacts']);
        self::assertTrue($this->reload($fiche)->isImposed('ects'));
        // la valeur propre de l'EC reste intacte : elle redeviendra effective si l'imposition est retirée
        self::assertSame(3, $this->ecValue($formation, $ec->nid, 'ects'));
    }

    public function testUsageBanner(): void
    {
        $porteuse = $this->formation('Licence porteuse');
        $autre = $this->formation('Licence utilisatrice');
        $fiche = $this->fiche($porteuse, $this->firstParcours($porteuse)->nid);
        $this->em->flush();

        $maquette = static::getContainer()->get(Maquette::class);
        foreach ([$porteuse, $autre] as $formation) {
            $doc = $maquette->open($formation);
            $this->ecsOf($this->firstParcours($formation))[0]->setAttribute('ficheMatiere', $fiche->getId());
            $maquette->save($formation, $doc);
        }

        $crawler = $this->client->request('GET', $this->url($fiche));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Utilisée par 2 EC dans 2 parcours', $crawler->filter('[data-usage-summary]')->text());
    }

    public function testHorsDiplomeForbiddenToResponsable(): void
    {
        $fiche = $this->horsDiplome();
        $this->client->request('POST', '/role/responsable');

        $this->client->request('GET', $this->url($fiche));
        self::assertResponseStatusCodeSame(403);

        $this->post($fiche, 'identite', ['libelle' => 'Modifiée'], [], false);
        self::assertResponseStatusCodeSame(403);
    }

    public function testLibelleTooLongRejected(): void
    {
        $formation = $this->formation();
        $fiche = $this->fiche($formation, $this->firstParcours($formation)->nid);
        $this->em->flush();

        $out = $this->post($fiche, 'identite', ['libelle' => str_repeat('x', 251)]);

        self::assertNotEmpty($out['issues']['libelle'] ?? []);
        self::assertSame('Fiche de test', $this->reload($fiche)->getLibelle());
    }

    private function formation(string $name = 'Licence fiche'): Formation
    {
        return FormationFactory::create(static::getContainer())->fromType('L', 3, $name, avecParcours: true, withLeaves: true);
    }

    private function fiche(Formation $formation, string $parcoursNid): FicheMatiere
    {
        $fiche = (new FicheMatiere())->setFormation($formation)->setParcoursNid($parcoursNid)
            ->setData(['libelle' => 'Fiche de test']);
        $this->em->persist($fiche);

        return $fiche;
    }

    private function horsDiplome(): FicheMatiere
    {
        $fiche = (new FicheMatiere())->setHorsDiplome(true)->setData(['libelle' => 'Fiche hors diplôme']);
        $this->em->persist($fiche);
        $this->em->flush();

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

    private function url(FicheMatiere $fiche, string $tab = 'identite'): string
    {
        return sprintf('/fiches-matieres/%d/%s', $fiche->getId(), $tab);
    }

    /**
     * @param array<string, mixed> $p
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    /** Sans JavaScript : l'imposition à confirmer réaffiche la page avec les EC touchés et le bouton de confirmation. */
    public function testImposingWithoutJsShowsImpactsAndConfirmButton(): void
    {
        $formation = $this->formation();
        $parcours = $this->firstParcours($formation);
        $fiche = $this->fiche($formation, $parcours->nid)->setDefault('ects', 6);
        $this->em->flush();
        $maquette = static::getContainer()->get(Maquette::class);
        $doc = $maquette->open($formation);
        $this->ecsOf($parcours)[0]->setAttribute('ficheMatiere', $fiche->getId())
            ->setAttribute('sources', ['ects' => 'ec'])->setAttribute('ects', 3);
        $maquette->save($formation, $doc);

        $crawler = $this->client->request('POST', $this->url($fiche, 'mccc'), [
            'p' => ['ects' => '6', 'ectsImposes' => 'oui'],
            '_csrf_token' => $this->csrfToken('fiche_matiere'),
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertCount(1, $crawler->filter('button[name="confirmImpact"]'));
        self::assertFalse($this->reload($fiche)->isImposed('ects'));
    }

    public function testPostToTabAbsentForHorsDiplomeIs404(): void
    {
        $fiche = $this->horsDiplome();
        $this->client->request('POST', $this->url($fiche, 'competences'), [
            'p' => ['libelle' => 'Modifiée'],
            '_csrf_token' => $this->csrfToken('fiche_matiere'),
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    private function post(FicheMatiere $fiche, string $tab, array $p, array $extra = [], bool $assertOk = true): array
    {
        $this->client->request('POST', $this->url($fiche, $tab), [
            'p' => $p,
            '_csrf_token' => $this->csrfToken('fiche_matiere'),
        ] + $extra, [], ['HTTP_ACCEPT' => 'application/json']);
        if ($assertOk) {
            self::assertResponseIsSuccessful();
        } elseif (403 === $this->client->getResponse()->getStatusCode()) {
            return [];
        }

        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function reload(FicheMatiere $fiche): FicheMatiere
    {
        $id = $fiche->getId();
        $this->em->clear();

        return $this->em->find(FicheMatiere::class, $id);
    }

    private function ecValue(Formation $formation, string $nid, string $key): mixed
    {
        $this->em->clear();
        $fresh = $this->em->find(Formation::class, $formation->getId());

        return static::getContainer()->get(Maquette::class)->open($fresh)->node($nid)?->getAttribute($key);
    }
}
