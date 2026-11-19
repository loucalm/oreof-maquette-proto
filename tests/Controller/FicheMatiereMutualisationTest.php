<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\FicheMatiere;
use App\Entity\FicheMatiereMutualisation;
use App\Entity\Formation;
use App\FicheMatiere\EcValueResolver;
use App\Maquette\Completion;
use App\Maquette\Doc\TreeNode;
use App\Maquette\Maquette;
use App\Tests\Support\FormationFactory;
use App\Tests\Support\ResetsDatabase;
use App\Tests\Support\SameOriginCsrf;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Mutualisation d'une fiche, retrait avec impacts et changement de porteur. */
final class FicheMatiereMutualisationTest extends WebTestCase
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

    public function testAddAndRemove(): void
    {
        [$porteuse, $utilisatrice] = $this->formations('Porteuse', 'Utilisatrice');
        $fiche = $this->fiche($porteuse);
        $this->em()->flush();
        $this->attachFiche($utilisatrice, $fiche);

        $out = $this->post($this->url($fiche, '/mutualisation'), [
            'formation' => $utilisatrice->getId(),
            'parcours' => $this->parcoursNid($utilisatrice),
        ]);
        self::assertTrue($out['saved']);
        self::assertCount(1, $this->mutualisations($fiche));

        $mutualisation = $this->mutualisations($fiche)[0];
        $out = $this->post($this->url($fiche, '/mutualisation/'.$mutualisation->getId().'/retirer'), [], 422);
        self::assertNotEmpty($out['impacts']);
        self::assertCount(1, $this->mutualisations($fiche));

        $out = $this->post($this->url($fiche, '/mutualisation/'.$mutualisation->getId().'/retirer'), ['confirmImpact' => '1']);
        self::assertTrue($out['saved']);
        self::assertSame([], $this->mutualisations($fiche));
    }

    public function testClear(): void
    {
        [$porteuse, $utilisatrice] = $this->formations('Porteuse', 'Utilisatrice');
        $fiche = $this->fiche($porteuse);
        $this->em()->flush();
        $this->attachFiche($utilisatrice, $fiche);
        $autre = $this->formation('Autre utilisatrice');
        $autreNid = $this->parcoursNid($autre);
        $this->em()->persist(new FicheMatiereMutualisation($fiche, $utilisatrice, $this->parcoursNid($utilisatrice)));
        $this->em()->persist(new FicheMatiereMutualisation($fiche, $autre, $autreNid));
        $this->em()->flush();

        $out = $this->post($this->url($fiche, '/mutualisation/tout-retirer'), [], 422);
        self::assertNotEmpty($out['impacts']);
        self::assertCount(2, $this->mutualisations($fiche));

        $out = $this->post($this->url($fiche, '/mutualisation/tout-retirer'), ['confirmImpact' => '1']);
        self::assertTrue($out['saved']);
        self::assertSame([], $this->mutualisations($fiche));
    }

    public function testRemovalImpactsListedAndLinkKept(): void
    {
        [$porteuse, $utilisatrice] = $this->formations('Porteuse', 'Utilisatrice');
        $fiche = $this->fiche($porteuse);
        $this->em()->flush();
        $nid = $this->attachFiche($utilisatrice, $fiche);
        $this->em()->persist(new FicheMatiereMutualisation($fiche, $utilisatrice, $this->parcoursNid($utilisatrice)));
        $this->em()->flush();
        $mutualisation = $this->mutualisations($fiche)[0];
        $url = $this->url($fiche, '/mutualisation/'.$mutualisation->getId().'/retirer');

        $out = $this->post($url, [], 422);
        self::assertCount(1, $out['impacts']);

        $this->client->request('POST', $url, ['_csrf_token' => $this->csrfToken('fiche_matiere')]);
        self::assertResponseStatusCodeSame(422);
        self::assertCount(1, $this->client->getCrawler()->filter('button[name="confirmImpact"]'));

        $this->post($url, ['confirmImpact' => '1']);
        $ec = $this->node($utilisatrice, $nid);
        self::assertSame($fiche->getId(), $ec->getAttribute('ficheMatiere'));
        $labels = array_column(static::getContainer()->get(Completion::class)->missing($ec), 'label');
        self::assertContains('fiche matière non autorisée dans ce parcours', $labels);
    }

    public function testChangePorteurPinsFilledValues(): void
    {
        [$porteuse, $utilisatrice] = $this->formations('Porteuse', 'Utilisatrice');
        $fiche = $this->fiche($porteuse)->setDefault('ects', 5.0);
        $this->em()->flush();
        $this->em()->clear();
        $fiche = $this->em()->find(FicheMatiere::class, $fiche->getId());
        $nid = $this->attachFiche($utilisatrice, $fiche, ['ects' => 2.0]);

        $out = $this->post($this->url($fiche, '/porteur'), [
            'formation' => $utilisatrice->getId(),
            'parcours' => $this->parcoursNid($utilisatrice),
            'confirmImpact' => '1',
        ]);
        self::assertTrue($out['saved']);

        $saved = $this->reload($fiche);
        self::assertSame($utilisatrice->getId(), $saved->getFormation()->getId());
        self::assertSame($this->parcoursNid($utilisatrice), $saved->getParcoursNid());
        $ec = $this->node($utilisatrice, $nid);
        self::assertSame('ec', $ec->getAttribute('sources')['ects']);
        self::assertEquals(2, static::getContainer()->get(EcValueResolver::class)->value($ec, 'ects'));
    }

    public function testChangePorteurDropsForeignCompetencies(): void
    {
        [$porteuse, $utilisatrice] = $this->formations('Porteuse', 'Utilisatrice');
        $old = $this->competence($porteuse, $this->parcoursNid($porteuse), 'Compétence ancienne porteur');
        $this->competence($utilisatrice, $this->parcoursNid($utilisatrice), 'Compétence nouveau porteur');
        $parcoursNid = $this->parcoursNid($utilisatrice);
        $label = $old->getLabel();
        $fiche = $this->fiche($porteuse)->setDefault('competencies', [$label]);
        $this->em()->flush();
        $this->em()->clear();
        $fiche = $this->em()->find(FicheMatiere::class, $fiche->getId());
        $url = $this->url($fiche, '/porteur');
        $data = ['formation' => $utilisatrice->getId(), 'parcours' => $parcoursNid];

        $out = $this->post($url, $data, 422);
        self::assertNotEmpty($out['impacts']);
        self::assertStringContainsString($label, implode(' ', array_column($out['impacts'], 'path')));
        self::assertSame([$label], $this->reload($fiche)->getDefault('competencies'));

        $out = $this->post($url, $data + ['confirmImpact' => '1']);
        self::assertTrue($out['saved']);
        self::assertSame([], $this->reload($fiche)->getDefault('competencies'));
    }

    /** @return array{0: Formation, 1: Formation} */
    public function testRemovalWithoutImpactAppliesDirectly(): void
    {
        [$porteuse, $utilisatrice] = $this->formations('Porteuse', 'Utilisatrice');
        $fiche = $this->fiche($porteuse);
        $this->em()->flush();
        $this->post($this->url($fiche, '/mutualisation'), ['formation' => $utilisatrice->getId(), 'parcours' => $this->parcoursNid($utilisatrice)]);

        $mutualisation = $this->mutualisations($fiche)[0];
        $out = $this->post($this->url($fiche, '/mutualisation/'.$mutualisation->getId().'/retirer'), []);

        self::assertTrue($out['saved']);
        self::assertSame([], $this->mutualisations($fiche));
    }

    public function testHorsDiplomeMutualisationForbiddenToResponsable(): void
    {
        [, $utilisatrice] = $this->formations('Porteuse', 'Utilisatrice');
        $fiche = (new FicheMatiere())->setHorsDiplome(true)->setData(['libelle' => 'Anglais']);
        $this->em()->persist($fiche);
        $this->em()->flush();
        $this->client->request('POST', '/role/responsable');

        $this->client->request('POST', $this->url($fiche, '/mutualisation'), [
            'formation' => $utilisatrice->getId(), 'parcours' => $this->parcoursNid($utilisatrice),
            '_csrf_token' => $this->csrfToken('fiche_matiere'),
        ], [], ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->mutualisations($fiche));
    }

    private function formations(string $porteuse, string $utilisatrice): array
    {
        return [$this->formation($porteuse), $this->formation($utilisatrice)];
    }

    private function formation(string $name): Formation
    {
        return FormationFactory::create(static::getContainer())->fromType('L', 3, $name, avecParcours: true, withLeaves: true);
    }

    private function fiche(Formation $formation): FicheMatiere
    {
        $fiche = (new FicheMatiere())->setFormation($formation)->setParcoursNid($this->parcoursNid($formation))
            ->setData(['libelle' => 'Fiche de test']);
        $this->em()->persist($fiche);

        return $fiche;
    }

    private function parcoursNid(Formation $formation): string
    {
        return static::getContainer()->get(Maquette::class)->open($formation)->parcoursNodes()[0]->nid;
    }

    /** @param array<string, mixed> $attributes */
    private function attachFiche(Formation $formation, FicheMatiere $fiche, array $attributes = []): string
    {
        $formation = $this->em()->find(Formation::class, $formation->getId());
        $maquette = static::getContainer()->get(Maquette::class);
        $doc = $maquette->open($formation);
        $nid = null;
        foreach ($doc->allNodes() as $node) {
            if (!$node->getType()->isElementLevel()) {
                continue;
            }
            $node->setAttribute('ficheMatiere', $fiche->getId());
            foreach ($attributes as $key => $value) {
                $node->setAttribute($key, $value);
            }
            $nid = $node->nid;
            break;
        }
        self::assertNotNull($nid, 'Aucun EC dans la formation.');
        $maquette->save($formation, $doc);

        return $nid;
    }

    private function competence(Formation $formation, string $parcoursNid, string $label): TreeNode
    {
        $formation = $this->em()->find(Formation::class, $formation->getId());
        $maquette = static::getContainer()->get(Maquette::class);
        $doc = $maquette->open($formation);
        $bloc = $doc->addNode($parcoursNid, 'bloc_competences', 'Bloc');
        $competence = $doc->addNode($bloc->nid, 'competence', $label);
        $maquette->save($formation, $doc);

        return $competence;
    }

    /** @return list<FicheMatiereMutualisation> */
    private function mutualisations(FicheMatiere $fiche): array
    {
        return $this->em()->getRepository(FicheMatiereMutualisation::class)->findBy(['fiche' => $fiche]);
    }

    private function node(Formation $formation, string $nid): TreeNode
    {
        $fresh = $this->em()->find(Formation::class, $formation->getId());

        return static::getContainer()->get(Maquette::class)->open($fresh)->node($nid) ?? self::fail('EC introuvable.');
    }

    private function reload(FicheMatiere $fiche): FicheMatiere
    {
        $id = $fiche->getId();
        $this->em()->clear();

        return $this->em()->find(FicheMatiere::class, $id);
    }

    private function url(FicheMatiere $fiche, string $suffix): string
    {
        return sprintf('/fiches-matieres/%d%s', $fiche->getId(), $suffix);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function post(string $url, array $params, int $status = 200): array
    {
        $this->client->request('POST', $url, ['_csrf_token' => $this->csrfToken('fiche_matiere')] + $params, [], ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseStatusCodeSame($status);
        $content = (string) $this->client->getResponse()->getContent();

        return '' === $content ? [] : json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get('doctrine')->getManager();
    }
}
