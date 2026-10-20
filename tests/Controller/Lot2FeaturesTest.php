<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\DerogationRequest;
use App\Entity\Formation;
use App\Enum\FormationPhase;
use App\Maquette\Doc\MaquetteDoc;
use App\Maquette\Maquette;
use App\Tests\Support\FormationFactory;
use App\Tests\Support\ResetsDatabase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class Lot2FeaturesTest extends WebTestCase
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

    private function reload(Formation $formation): array
    {
        $this->em->clear();
        $fresh = $this->em->find(Formation::class, $formation->getId());

        return [$fresh, static::getContainer()->get(Maquette::class)->open($fresh)];
    }

    public function testConsolidationIgnoresContentButKeepsLabel(): void
    {
        $formation = $this->factory()->fromType('L', 3, withLeaves: true);
        $formation->setPhase(FormationPhase::Consolidation);
        $this->em->flush();
        $ec = $this->factory()->first(static::getContainer()->get(Maquette::class)->open($formation), 'ec');

        $this->client->request('POST', sprintf('/formations/%d/nodes/%s', $formation->getId(), $ec->nid), [
            'label' => 'Nouveau nom', 'attr_ects' => '6',
        ]);

        /** @var MaquetteDoc $doc */
        [, $doc] = $this->reload($formation);
        self::assertSame('Nouveau nom', $doc->node($ec->nid)->label);
        self::assertNull($doc->node($ec->nid)->getAttribute('ects'));
    }

    public function testBatchSwitchesSelectedFormationsToConstruction(): void
    {
        $a = $this->factory()->fromType('L', 3, 'A', withLeaves: true);
        $b = $this->factory()->fromType('L', 3, 'B', withLeaves: true);
        $a->setPhase(FormationPhase::Consolidation);
        $b->setPhase(FormationPhase::Consolidation);
        $this->em->flush();

        $this->client->request('POST', '/formations/phase/construction', ['ids' => [$a->getId()]]);

        $this->em->clear();
        self::assertSame(FormationPhase::Construction, $this->em->find(Formation::class, $a->getId())->getPhase());
        self::assertSame(FormationPhase::Consolidation, $this->em->find(Formation::class, $b->getId())->getPhase());
    }

    public function testApprovingADerogationCanUnlockTheNode(): void
    {
        $formation = $this->factory()->fromType('L', 3, withLeaves: true);
        $annee = $this->factory()->first(static::getContainer()->get(Maquette::class)->open($formation), 'annee');
        $derogation = new DerogationRequest($formation, 'Supprimer cette année', $annee->nid, $annee->label);
        $this->em->persist($derogation);
        $this->em->flush();

        $this->client->request('POST', sprintf('/administration/derogations/%d/resoudre', $derogation->getId()), [
            'status' => 'approved', 'unlock' => '1',
        ]);

        [, $doc] = $this->reload($formation);
        self::assertSame([], $doc->node($annee->nid)->locked);
    }

    public function testMutualizedCopyDetectsSourceChangeAndRefreshes(): void
    {
        $maquette = static::getContainer()->get(Maquette::class);
        $source = $this->factory()->fromType('L', 3, 'Source', withLeaves: true);
        $target = $this->factory()->fromType('L', 3, 'Cible', withLeaves: true);
        $srcUe = $this->factory()->first($maquette->open($source), 'ue');
        $tgtSemestre = $this->factory()->first($maquette->open($target), 'semestre');

        $this->client->request('POST', sprintf('/formations/%d/nodes/%s/raccrocher/%d/%s', $target->getId(), $tgtSemestre->nid, $source->getId(), $srcUe->nid));
        [$target, $doc] = $this->reload($target);
        $copy = array_values(array_filter($doc->allNodes(), static fn ($n) => null !== $n->getMutualizedFrom()))[0];
        self::assertFalse($maquette->mutualizationSource($copy)['changed']);

        // la source évolue
        [$source, $srcDoc] = $this->reload($source);
        $srcDoc->node($srcUe->nid)->setLabel('UE renommée à la source');
        $maquette->save($source, $srcDoc);
        [$target, $doc] = $this->reload($target);
        $copy = $doc->node($copy->nid);
        self::assertTrue(static::getContainer()->get(Maquette::class)->mutualizationSource($copy)['changed']);

        $this->client->request('POST', sprintf('/formations/%d/nodes/%s/resynchroniser', $target->getId(), $copy->nid));
        [, $doc] = $this->reload($target);
        $labels = array_map(static fn ($n) => $n->label, array_filter($doc->allNodes(), static fn ($n) => null !== $n->getMutualizedFrom()));
        self::assertSame(['UE renommée à la source'], array_values($labels));
    }

    public function testHelpSaveIsAdminOnlyAndRejectsExternalRedirect(): void
    {
        $this->client->request('POST', '/role/responsable');
        $this->client->request('POST', '/administration/aide/formation_index', ['title' => 'x', 'body' => 'y', 'back' => '/']);
        self::assertResponseRedirects('/');
        self::assertStringNotContainsString("formation_index.title: x", (string) file_get_contents(static::getContainer()->getParameter('kernel.project_dir').'/translations/help.fr.yaml'));

        $this->client->request('POST', '/role/admin');
        $help = static::getContainer()->getParameter('kernel.project_dir').'/translations/help.fr.yaml';
        $before = (string) file_get_contents($help);
        try {
            $this->client->request('POST', '/administration/aide/formation_index', ['title' => 'Titre', 'body' => 'Texte', 'back' => '//evil.example']);
            self::assertResponseRedirects('/');
        } finally {
            file_put_contents($help, $before); // ne pas polluer le fichier versionné
            foreach (glob(dirname($help).'/help.fr.yaml.bak_*') ?: [] as $bak) {
                unlink($bak);
            }
        }
    }
}
