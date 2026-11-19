<?php

declare(strict_types=1);

namespace App\Tests\FicheMatiere;

use App\Entity\FicheMatiere;
use App\Entity\FicheMatiereMutualisation;
use App\Entity\Formation;
use App\FicheMatiere\FicheMatiereAccess;
use App\FicheMatiere\FicheRelation;
use App\Maquette\Doc\MaquetteDoc;
use App\Maquette\Doc\TreeNode;
use App\Maquette\Maquette;
use App\Tests\Support\DatabaseTestCase;
use App\Tests\Support\FormationFactory;

final class FicheMatiereAccessTest extends DatabaseTestCase
{
    private FicheMatiereAccess $access;
    private Formation $formation;
    private MaquetteDoc $doc;
    /** @var list<TreeNode> */
    private array $parcours;

    protected function setUp(): void
    {
        parent::setUp();
        $this->access = static::getContainer()->get(FicheMatiereAccess::class);
        $this->formation = FormationFactory::create(static::getContainer())->fromType('L', 3, avecParcours: true, withLeaves: true);
        $this->doc = static::getContainer()->get(Maquette::class)->open($this->formation);
        // deux vrais parcours frères (la fabrique n'en crée qu'un)
        $this->doc->duplicateNode($this->doc->parcoursNodes()[0]);
        $this->parcours = $this->doc->parcoursNodes();
        self::assertCount(2, $this->parcours);
        self::assertNotNull($this->parcours[0]->nid);
        self::assertNotNull($this->parcours[1]->nid);
        self::assertNotSame($this->parcours[0]->nid, $this->parcours[1]->nid);
    }

    public function testRelationPorteur(): void
    {
        $fiche = $this->fiche($this->parcours[0]->nid, 'A');
        $this->em->flush();

        self::assertSame(FicheRelation::Porteur, $this->access->relation($fiche, $this->ecOf($this->parcours[0])));
        self::assertTrue($this->access->isPorteurOf($fiche, $this->ecOf($this->parcours[0])));
        self::assertSame($this->parcours[0], $this->access->parcoursOf($this->ecOf($this->parcours[0])));
    }

    public function testRelationAncetre(): void
    {
        $portail = $this->parcours[0];
        $sub = $this->doc->addNode(null, 'parcours', 'Sous-parcours');
        $sub->setParcoursParent($portail);
        $subsub = $this->doc->addNode(null, 'parcours', 'Sous-sous-parcours');
        $subsub->setParcoursParent($sub);
        $ec = $this->doc->addNode($subsub->nid, 'ec', 'EC profond');
        $fiche = $this->fiche($portail->nid, 'Portail');
        $this->em->flush();

        self::assertSame(FicheRelation::Ancetre, $this->access->relation($fiche, $ec));
        self::assertFalse($this->access->isPorteurOf($fiche, $ec));
        self::assertSame([$fiche], $this->access->available($ec));
    }

    public function testRelationMutualisee(): void
    {
        $fiche = $this->fiche($this->parcours[1]->nid, 'Frère');
        $this->em->persist(new FicheMatiereMutualisation($fiche, $this->formation, $this->parcours[0]->nid));
        $this->em->flush();

        self::assertSame(FicheRelation::Mutualisee, $this->access->relation($fiche, $this->ecOf($this->parcours[0])));
    }

    public function testRelationHorsDiplome(): void
    {
        $fiche = (new FicheMatiere())->setHorsDiplome(true)->setData(['libelle' => 'Libre']);
        $this->em->persist($fiche);
        $this->em->flush();

        self::assertSame(FicheRelation::HorsDiplome, $this->access->relation($fiche, $this->ecOf($this->parcours[0])));
    }

    public function testRelationInterdite(): void
    {
        $fiche = $this->fiche($this->parcours[1]->nid, 'Frère');
        $this->em->flush();

        self::assertSame(FicheRelation::Interdite, $this->access->relation($fiche, $this->ecOf($this->parcours[0])));
        self::assertNotContains($fiche, $this->access->available($this->ecOf($this->parcours[0])));
    }

    public function testAvailableOrderAndNoDuplicate(): void
    {
        $nid = $this->parcours[0]->nid;
        $porteeEtMutualisee = $this->fiche($nid, 'Zeta');
        $this->em->persist(new FicheMatiereMutualisation($porteeEtMutualisee, $this->formation, $nid));
        $autre = $this->fiche($this->parcours[1]->nid, 'Alpha');
        $this->em->persist(new FicheMatiereMutualisation($autre, $this->formation, $nid));
        $libre = (new FicheMatiere())->setHorsDiplome(true)->setData(['libelle' => 'Beta']);
        $this->em->persist($libre);
        $this->em->flush();

        self::assertSame(
            [$porteeEtMutualisee, $autre, $libre],
            $this->access->available($this->ecOf($this->parcours[0])),
        );
    }

    public function testMonoParcoursEc(): void
    {
        $formation = FormationFactory::create(static::getContainer())->fromType('L', 3, 'Mono', avecParcours: false, withLeaves: true);
        $doc = static::getContainer()->get(Maquette::class)->open($formation);
        $ec = $this->firstEc($doc);
        $fiche = (new FicheMatiere())->setFormation($formation)->setData(['libelle' => 'Seule']);
        $this->em->persist($fiche);
        $this->em->flush();

        self::assertNull($this->access->parcoursOf($ec));
        self::assertSame(FicheRelation::Porteur, $this->access->relation($fiche, $ec));
        self::assertSame([$fiche], $this->access->available($ec));
    }

    public function testMutualisationToAncestorReachesDescendant(): void
    {
        $portail = $this->parcours[0];
        $sub = $this->doc->addNode(null, 'parcours', 'Sous-parcours');
        $sub->setParcoursParent($portail);
        $ec = $this->doc->addNode($sub->nid, 'ec', 'EC');
        $fiche = $this->fiche($this->parcours[1]->nid, 'Frère');
        $this->em->persist(new FicheMatiereMutualisation($fiche, $this->formation, $portail->nid));
        $this->em->flush();

        self::assertSame(FicheRelation::Mutualisee, $this->access->relation($fiche, $ec));
        self::assertSame([$fiche], $this->access->available($ec));
    }

    public function testParcoursParentCycleDoesNotLoop(): void
    {
        $a = $this->parcours[0];
        $b = $this->doc->addNode(null, 'parcours', 'B');
        $b->setParcoursParent($a);
        $a->setParcoursParent($b);
        $ec = $this->doc->addNode($a->nid, 'ec', 'EC');
        $fiche = $this->fiche($a->nid, 'A');
        $this->em->flush();

        self::assertSame(FicheRelation::Porteur, $this->access->relation($fiche, $ec));
        self::assertSame([$fiche], $this->access->available($ec));
        $autre = $this->fiche($b->nid, 'B');
        $this->em->flush();
        self::assertSame(FicheRelation::Ancetre, $this->access->relation($autre, $ec));
    }

    public function testAvailableFullOrder(): void
    {
        $portail = $this->parcours[0];
        $sub = $this->doc->addNode(null, 'parcours', 'Sous-parcours');
        $sub->setParcoursParent($portail);
        $ec = $this->doc->addNode($sub->nid, 'ec', 'EC');
        $porteuse = $this->fiche($sub->nid, 'Zz porteuse');
        $this->em->persist(new FicheMatiereMutualisation($porteuse, $this->formation, $sub->nid));
        $ancetre = $this->fiche($portail->nid, 'Yy ancêtre');
        $mut = $this->fiche($this->parcours[1]->nid, 'Aa mutualisée');
        $this->em->persist(new FicheMatiereMutualisation($mut, $this->formation, $sub->nid));
        $libre = (new FicheMatiere())->setHorsDiplome(true)->setData(['libelle' => 'Bb libre']);
        $this->em->persist($libre);
        $this->em->flush();

        self::assertSame([$porteuse, $ancetre, $mut, $libre], $this->access->available($ec));
    }

    private function fiche(?string $nid, string $libelle): FicheMatiere
    {
        $fiche = (new FicheMatiere())->setFormation($this->formation)->setParcoursNid($nid)->setData(['libelle' => $libelle]);
        $this->em->persist($fiche);

        return $fiche;
    }

    private function ecOf(TreeNode $parcours): TreeNode
    {
        $stack = [$parcours];
        while ($stack) {
            $n = array_pop($stack);
            if ($n->isLeaf()) {
                return $n;
            }
            array_push($stack, ...$n->getChildren());
        }

        throw new \LogicException('Aucun EC sous ce parcours.');
    }

    private function firstEc(MaquetteDoc $doc): TreeNode
    {
        foreach ($doc->allNodes() as $n) {
            if ($n->isLeaf()) {
                return $n;
            }
        }

        throw new \LogicException('Aucun EC.');
    }
}
