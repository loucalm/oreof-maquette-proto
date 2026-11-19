<?php

declare(strict_types=1);

namespace App\Tests\FicheMatiere;

use App\Entity\FicheMatiere;
use App\Entity\FicheMatiereMutualisation;
use App\Entity\Formation;
use App\FicheMatiere\EcValueResolver;
use App\Maquette\Doc\MaquetteDoc;
use App\Maquette\Doc\TreeNode;
use App\Maquette\Maquette;
use App\Repository\FicheMatiereMutualisationRepository;
use App\Repository\FicheMatiereRepository;
use App\Tests\Support\FormationFactory;
use App\Tests\Support\ResetsDatabase;
use App\Tests\Support\SameOriginCsrf;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Cohérence des fiches matière face aux actions sur la maquette (spec §7.2 bis, §9 — tâche 12). */
final class CoherenceTest extends WebTestCase
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

    public function testParentSourceWithEnfantsIdentiques(): void
    {
        [$formation, $bloc, $ec] = $this->blocFixture();

        $this->post(sprintf('/formations/%d/nodes/%s', $formation->getId(), $bloc->nid), [
            'label' => 'Bloc de choix',
            'attr_hours' => ['pres' => ['cm' => 10]],
            'enfantsIdentiques' => ['hours' => '1'],
        ]);

        $doc = $this->doc($formation);
        $savedBloc = $doc->node($bloc->nid);
        self::assertInstanceOf(TreeNode::class, $savedBloc);
        self::assertSame(['pres' => ['cm' => 10.0]], $savedBloc->getAttribute('hours'));
        self::assertTrue($savedBloc->getAttribute('enfantsIdentiques')['hours'] ?? false);

        $resolved = $this->resolver()->resolve($doc->node($ec->nid), 'hours');
        self::assertSame(EcValueResolver::SOURCE_PARENT, $resolved->source);
        self::assertSame(10.0, (float) ($resolved->value['pres']['cm'] ?? 0));
    }

    public function testEnteringBlocPinsFilledValues(): void
    {
        [$formation, $bloc, $ec] = $this->blocFixture();
        $this->saveDoc($formation, static function (MaquetteDoc $doc) use ($bloc, $ec): void {
            $doc->node($bloc->nid)
                ->setAttribute('hours', ['pres' => ['cm' => 10]])
                ->setAttribute('enfantsIdentiques', ['hours' => true]);
            $doc->node($ec->nid)->setAttribute('hours', ['pres' => ['cm' => 12]]);
        });

        $this->postJson(sprintf('/formations/%d/nodes/%s/move', $formation->getId(), $ec->nid), [
            'parentId' => $bloc->nid,
            'index' => 0,
        ]);

        $node = $this->doc($formation)->node($ec->nid);
        self::assertInstanceOf(TreeNode::class, $node);
        self::assertSame($bloc->nid, $node->getParent()?->nid);
        self::assertSame('ec', $node->getAttribute('sources')['hours'] ?? null);
        $resolved = $this->resolver()->resolve($node, 'hours');
        self::assertSame(EcValueResolver::SOURCE_EC, $resolved->source);
        self::assertSame(12.0, (float) ($resolved->value['pres']['cm'] ?? 0));
    }

    public function testMoveToOtherParcoursPins(): void
    {
        [$formation, $parcours] = $this->twoParcours();
        $source = $this->ecsOf($parcours[0])[0];
        $target = $this->ecsOf($parcours[1])[0];
        $this->saveDoc($formation, static function (MaquetteDoc $doc) use ($source): void {
            $doc->node($source->nid)
                ->setAttribute('hours', ['pres' => ['cm' => 12]])
                ->setAttribute('ects', 4.0);
        });

        $this->postJson(sprintf('/formations/%d/nodes/%s/move', $formation->getId(), $source->nid), [
            'parentId' => $target->getParent()?->nid,
            'index' => 0,
        ]);

        $node = $this->doc($formation)->node($source->nid);
        self::assertInstanceOf(TreeNode::class, $node);
        self::assertSame($parcours[1]->nid, $this->access()->parcoursOf($node)?->nid);
        $sources = $node->getAttribute('sources');
        self::assertSame('ec', $sources['hours'] ?? null);
        self::assertSame('ec', $sources['ects'] ?? null);
        self::assertSame(12.0, (float) ($this->resolver()->resolve($node, 'hours')->value['pres']['cm'] ?? 0));
    }

    public function testPasteToUnauthorisedParcoursDuplicatesFiche(): void
    {
        [$formation, $parcours] = $this->twoParcours();
        $source = $this->ecsOf($parcours[0])[0];
        $target = $this->ecsOf($parcours[1])[0];
        $fiche = $this->fiche($formation, $parcours[0]->nid, 'Algo', ['hours' => ['pres' => ['cm' => 12]]]);
        $this->link($formation, $source->nid, $fiche);

        $this->post(sprintf('/formations/%d/nodes/%s/copier', $formation->getId(), $source->nid));
        $this->post(sprintf('/formations/%d/nodes/%s/coller', $formation->getId(), $target->nid));

        $copy = $this->doc($formation)->node($target->nid);
        self::assertInstanceOf(TreeNode::class, $copy);
        $newId = $this->resolver()->ficheId($copy);
        self::assertNotNull($newId);
        self::assertNotSame($fiche->getId(), $newId);
        $newFiche = $this->fiches()->find($newId);
        self::assertInstanceOf(FicheMatiere::class, $newFiche);
        self::assertSame($formation->getId(), $newFiche->getFormation()?->getId());
        self::assertSame($parcours[1]->nid, $newFiche->getParcoursNid());
        self::assertStringContainsString('Algo', $newFiche->getLibelle());
    }

    public function testPasteInSameParcoursKeepsFiche(): void
    {
        $formation = $this->factory()->fromType('L', 3, avecParcours: true, withLeaves: true);
        $parcours = $this->doc($formation)->parcoursNodes()[0];
        [$source, $target] = $this->ecsOf($parcours);
        $fiche = $this->fiche($formation, $parcours->nid, 'Algo');
        $this->link($formation, $source->nid, $fiche);

        $this->post(sprintf('/formations/%d/nodes/%s/copier', $formation->getId(), $source->nid));
        $this->post(sprintf('/formations/%d/nodes/%s/coller', $formation->getId(), $target->nid));

        $copy = $this->doc($formation)->node($target->nid);
        self::assertInstanceOf(TreeNode::class, $copy);
        self::assertSame($fiche->getId(), $this->resolver()->ficheId($copy));
    }

    public function testAttachMutualisesFiches(): void
    {
        $sourceFormation = $this->factory()->fromType('L', 3, avecParcours: true, withLeaves: true);
        $targetFormation = $this->factory()->fromType('L', 3, avecParcours: true, withLeaves: true, name: 'Formation cible');
        $sourceParcours = $this->doc($sourceFormation)->parcoursNodes()[0];
        $targetParcours = $this->doc($targetFormation)->parcoursNodes()[0];
        $sourceEc = $this->ecsOf($sourceParcours)[0];
        $targetUe = $this->ecsOf($targetParcours)[0]->getParent();
        self::assertInstanceOf(TreeNode::class, $targetUe);
        $fiche = $this->fiche($sourceFormation, $sourceParcours->nid, 'Algo');
        $this->link($sourceFormation, $sourceEc->nid, $fiche);
        $this->saveDoc($sourceFormation, static function (MaquetteDoc $doc) use ($sourceEc): void {
            $doc->node($sourceEc->nid)->setMutualized(true);
        });

        $this->post(sprintf(
            '/formations/%d/nodes/%s/raccrocher/%d/%s',
            $targetFormation->getId(),
            $targetUe->nid,
            $sourceFormation->getId(),
            $sourceEc->nid,
        ));

        $mutualisation = $this->mutualisations()->findOneBy([
            'fiche' => $fiche,
            'formation' => $this->fresh($targetFormation),
            'parcoursNid' => $targetParcours->nid,
        ]);
        self::assertInstanceOf(FicheMatiereMutualisation::class, $mutualisation);
    }

    public function testFormationDeleteRefusedWhenFicheUsedElsewhere(): void
    {
        $sourceFormation = $this->factory()->fromType('L', 3, avecParcours: true, withLeaves: true);
        $otherFormation = $this->factory()->fromType('L', 3, avecParcours: true, withLeaves: true, name: 'Autre formation');
        $sourceParcours = $this->doc($sourceFormation)->parcoursNodes()[0];
        $otherParcours = $this->doc($otherFormation)->parcoursNodes()[0];
        $fiche = $this->fiche($sourceFormation, $sourceParcours->nid, 'Algo partagée');
        $otherEc = $this->ecsOf($otherParcours)[0];
        $this->link($otherFormation, $otherEc->nid, $fiche);
        $target = $this->fresh($otherFormation);
        $managedFiche = $this->em->find(FicheMatiere::class, $fiche->getId());
        self::assertInstanceOf(FicheMatiere::class, $managedFiche);
        $this->em->persist(new FicheMatiereMutualisation($managedFiche, $target, $otherParcours->nid));
        $this->em->flush();

        $this->post(sprintf('/formations/%d/supprimer', $sourceFormation->getId()));

        $this->em->clear();
        self::assertInstanceOf(Formation::class, $this->em->find(Formation::class, $sourceFormation->getId()));
        $messages = implode(' ', array_merge(...array_values($this->flashes())));
        self::assertStringContainsString('Suppression refusée', $messages);
        self::assertStringContainsString('Algo partagée', $messages);
        self::assertStringContainsString($otherFormation->getName(), $messages);
    }

    public function testFormationDeleteRemovesItsFiches(): void
    {
        $formation = $this->factory()->fromType('L', 3, avecParcours: true, withLeaves: true);
        $parcours = $this->doc($formation)->parcoursNodes()[0];
        $fiche = $this->fiche($formation, $parcours->nid, 'Algo locale');
        $this->link($formation, $this->ecsOf($parcours)[0]->nid, $fiche);

        $this->post(sprintf('/formations/%d/supprimer', $formation->getId()));

        $this->em->clear();
        self::assertNull($this->em->find(Formation::class, $formation->getId()));
        self::assertNull($this->fiches()->find($fiche->getId()));
    }

    public function testUndoAfterFicheDeletionDoesNotCrash(): void
    {
        $formation = $this->factory()->fromType('L', 3, avecParcours: true, withLeaves: true);
        $parcours = $this->doc($formation)->parcoursNodes()[0];
        $ec = $this->ecsOf($parcours)[0];
        $fiche = $this->fiche($formation, $parcours->nid, 'Algo supprimée');
        $this->link($formation, $ec->nid, $fiche);
        // retrait de l'EC : la fiche n'est plus utilisée, sa suppression est autorisée
        $this->saveDoc($formation, static function (MaquetteDoc $doc) use ($ec): void {
            $doc->removeNode($doc->node($ec->nid));
        });

        $this->post(sprintf('/fiches-matieres/%d/supprimer', $fiche->getId()), [
            '_csrf_token' => $this->csrfToken('fiche_matiere'),
        ]);
        self::assertNull($this->fiches()->find($fiche->getId()));

        $this->post(sprintf('/formations/%d/undo', $formation->getId()));

        $this->client->request('GET', sprintf('/formations/%d', $formation->getId()));
        self::assertResponseIsSuccessful();
        $this->client->request('GET', sprintf('/formations/%d/nodes/%s', $formation->getId(), $ec->nid));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('fiche matière introuvable', $this->client->getResponse()->getContent());
    }

    // ─── helpers ──────────────────────────────────────────────

    private function factory(): FormationFactory
    {
        return FormationFactory::create(static::getContainer());
    }

    private function maquette(): Maquette
    {
        return static::getContainer()->get(Maquette::class);
    }

    private function resolver(): EcValueResolver
    {
        return static::getContainer()->get(EcValueResolver::class);
    }

    private function access(): \App\FicheMatiere\FicheMatiereAccess
    {
        return static::getContainer()->get(\App\FicheMatiere\FicheMatiereAccess::class);
    }

    private function fiches(): FicheMatiereRepository
    {
        return static::getContainer()->get(FicheMatiereRepository::class);
    }

    private function mutualisations(): FicheMatiereMutualisationRepository
    {
        return static::getContainer()->get(FicheMatiereMutualisationRepository::class);
    }

    private function fresh(Formation $formation): Formation
    {
        $this->em->clear();
        $fresh = $this->em->find(Formation::class, $formation->getId());
        self::assertInstanceOf(Formation::class, $fresh);
        // Après clear(), l'instance retournée par la fabrique est détachée : les fixtures chargent
        // les référentiels dans le même EntityManager, on la réattache avant de persister une fiche.
        $this->em->persist($fresh);

        return $fresh;
    }

    private function doc(Formation $formation): MaquetteDoc
    {
        return $this->maquette()->open($this->fresh($formation));
    }

    /** @param callable(MaquetteDoc): void $change */
    private function saveDoc(Formation $formation, callable $change): void
    {
        $fresh = $this->fresh($formation);
        $doc = $this->maquette()->open($fresh);
        $change($doc);
        $this->maquette()->save($fresh, $doc);
    }

    /** @return list<TreeNode> */
    private function ecsOf(TreeNode $node): array
    {
        $out = [];
        $walk = static function (TreeNode $n) use (&$walk, &$out): void {
            foreach ($n->getChildren() as $child) {
                if ($child->getType()->isElementLevel()) {
                    $out[] = $child;
                }
                $walk($child);
            }
        };
        $walk($node);

        return $out;
    }

    /** @return array{0: Formation, 1: TreeNode, 2: TreeNode} formation, bloc, EC */
    private function blocFixture(): array
    {
        $formation = $this->factory()->fromType('L', 3, avecParcours: true, withLeaves: true);
        $ec = $this->ecsOf($this->doc($formation)->parcoursNodes()[0])[0];
        $ue = $ec->getParent();
        self::assertInstanceOf(TreeNode::class, $ue);
        $bloc = null;
        $this->saveDoc($formation, static function (MaquetteDoc $doc) use ($ue, $ec, &$bloc): void {
            $bloc = $doc->addNode($ue->nid, 'bloc_choix', 'Bloc de choix');
            $doc->moveNode($doc->node($ec->nid), $bloc, 0);
        });
        self::assertInstanceOf(TreeNode::class, $bloc);

        return [$formation, $bloc, $ec];
    }

    /** @return array{0: Formation, 1: list<TreeNode>} */
    private function twoParcours(): array
    {
        $formation = $this->factory()->fromType('L', 3, avecParcours: true, withLeaves: true);
        $this->saveDoc($formation, static function (MaquetteDoc $doc): void {
            $doc->duplicateNode($doc->parcoursNodes()[0]);
        });
        $parcours = $this->doc($formation)->parcoursNodes();
        self::assertCount(2, $parcours);

        return [$formation, $parcours];
    }

    /** @param array<string, mixed> $defaults */
    private function fiche(Formation $formation, ?string $parcoursNid, string $libelle, array $defaults = []): FicheMatiere
    {
        $managed = $this->fresh($formation);
        $fiche = (new FicheMatiere())->setFormation($managed)->setParcoursNid($parcoursNid)
            ->setData(['libelle' => $libelle])->setDefaults($defaults);
        $this->em->persist($fiche);
        $this->em->flush();

        return $fiche;
    }

    private function link(Formation $formation, string $nid, FicheMatiere $fiche): void
    {
        $this->saveDoc($formation, static function (MaquetteDoc $doc) use ($nid, $fiche): void {
            $doc->node($nid)?->setAttribute('ficheMatiere', $fiche->getId());
        });
    }

    /** @param array<string, mixed> $params */
    private function post(string $url, array $params = []): void
    {
        $this->client->request('POST', $url, $params);
        $status = $this->client->getResponse()->getStatusCode();
        self::assertLessThan(400, $status, (string) $this->client->getResponse()->getContent());
    }

    /** @param array<string, mixed> $payload */
    private function postJson(string $url, array $payload): void
    {
        $this->client->request('POST', $url, [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($payload, \JSON_THROW_ON_ERROR));
        $status = $this->client->getResponse()->getStatusCode();
        self::assertLessThan(400, $status, (string) $this->client->getResponse()->getContent());
    }

    /** @return array<string, list<string>> */
    private function flashes(): array
    {
        return $this->client->getRequest()->getSession()->getFlashBag()->all();
    }
}
