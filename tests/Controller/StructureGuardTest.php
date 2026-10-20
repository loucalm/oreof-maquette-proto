<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Diplome\StructureInstantiator;
use App\Entity\DerogationRequest;
use App\Entity\Formation;
use App\Entity\FormationRevision;
use App\Entity\TypeDiplome;
use App\Enum\DerogationStatus;
use App\Maquette\Maquette;
use App\Tests\Support\ResetsDatabase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class StructureGuardTest extends WebTestCase
{
    use ResetsDatabase;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->resetDatabase();
    }

    /**
     * Licence (3 ans) dont S1 porte $ueInS1 UE (au moins) ; S2 reste au minimum. Écrite sans passer par le garde
     * quand elle dépasse les bornes.
     *
     * @return array{0: Formation, 1: string, 2: string, 3: string} formation, nid S1, nid S2, nid d'une UE de S2
     */
    private function licence(int $ueInS1): array
    {
        $type = $this->em->getRepository(TypeDiplome::class)->findOneByKey('L');
        $formation = new Formation('Licence gardée');
        $formation->setTypeDiplome($type);
        $this->em->persist($formation);
        $this->em->flush();

        $maquette = static::getContainer()->get(Maquette::class);
        $doc = $maquette->open($formation);
        (new StructureInstantiator())->instantiate($doc, $type->latestVersion(), 3, false);
        $semestres = array_values(array_filter($doc->allNodes(), static fn ($n) => 'semestre' === $n->typeKey));
        while (\count($semestres[0]->getChildren()) < $ueInS1) {
            $doc->addNode($semestres[0]->nid, 'ue');
        }
        $ueS2 = $semestres[1]->getChildren()[0];
        $doc->reindex();
        // écriture directe : sans garde ni révision
        $formation->setArbre($doc->dumpTree());
        $this->em->flush();
        $ids = [$semestres[0]->nid, $semestres[1]->nid, $ueS2->nid];
        // nouveau noyau : le Maquette de cette requête ne doit pas garder l'instantané « avant » de la mise en place
        $this->client->getKernel()->shutdown();
        $this->client->getKernel()->boot();
        $this->em = static::getContainer()->get('doctrine')->getManager();

        return [$this->em->find(Formation::class, $formation->getId()), ...$ids];
    }

    private function ueCount(int $fid, string $semestreNid): int
    {
        $this->em->clear();
        // lu dans la base (l'arbre enregistré), pas dans le cache du service
        $find = static function (array $nodes) use (&$find, $semestreNid): ?int {
            foreach ($nodes as $n) {
                if (($n['nid'] ?? null) === $semestreNid) {
                    return \count($n['children'] ?? []);
                }
                if (null !== $c = $find($n['children'] ?? [])) {
                    return $c;
                }
            }

            return null;
        };

        return $find($this->em->find(Formation::class, $fid)->getArbre());
    }

    private function add(Formation $f, string $parentNid): void
    {
        $this->client->request('POST', '/formations/'.$f->getId().'/nodes', ['parentId' => $parentNid, 'typeKey' => 'ue']);
    }

    public function testAddingSeventhUeIsRefusedAndTreeUnchanged(): void
    {
        [$f, $s1] = $this->licence(4);
        $fid = $f->getId();

        $this->add($f, $s1);
        $this->add($f, $s1);
        self::assertSame(6, $this->ueCount($fid, $s1));

        $this->add($f, $s1);
        self::assertResponseRedirects();
        self::assertSame(6, $this->ueCount($fid, $s1));

        $this->client->followRedirect();
        $html = html_entity_decode($this->client->getResponse()->getContent(), \ENT_QUOTES);
        self::assertStringContainsString('Modification refusée', $html);
        self::assertStringContainsString('Demander une dérogation', $html);
        self::assertStringContainsString('derogation=level:ue:count:max', $html);
    }

    public function testDerogationLinkPrefillsTheModal(): void
    {
        [$f, $s1] = $this->licence(6);

        $this->client->request('GET', '/formations/'.$f->getId().'?derogation=level:ue:count:max&scope='.$s1.'&value=7');
        $html = $this->client->getResponse()->getContent();
        self::assertStringContainsString('name="ruleKey" value="level:ue:count:max"', $html);
        self::assertStringContainsString('name="scopeNid" value="'.$s1.'"', $html);
        self::assertStringContainsString('name="allowedValue" value="7"', $html);
    }

    public function testMoveOverTheBoundAnswers422(): void
    {
        [$f, $s1, , $ueS2] = $this->licence(6);

        $this->client->request('POST', '/formations/'.$f->getId().'/nodes/'.$ueS2.'/move', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['parentId' => $s1, 'index' => 0]));

        self::assertResponseStatusCodeSame(422);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('level:ue:count:max', $data['violations'][0]['ruleKey']);
        self::assertSame($s1, $data['violations'][0]['scopeNid']);
        self::assertSame(7, $data['violations'][0]['actual']);
        self::assertSame(6, $this->ueCount($f->getId(), $s1));
    }

    public function testGrantedDerogationLetsTheActionThrough(): void
    {
        [$f, $s1] = $this->licence(6);
        $d = (new DerogationRequest($f, 'Besoin'))->withDeviation('level:ue:count:max', $s1, 7.0);
        $d->resolve(DerogationStatus::Approved, null);
        $this->em->persist($d);
        $this->em->flush();

        $this->add($f, $s1);
        self::assertSame(7, $this->ueCount($f->getId(), $s1));

        $this->add($f, $s1);
        self::assertSame(7, $this->ueCount($f->getId(), $s1));
    }

    public function testExistingViolationDoesNotBlockUnrelatedEdit(): void
    {
        [$f, $s1, , $ueS2] = $this->licence(7);

        $this->client->request('POST', '/formations/'.$f->getId().'/nodes/'.$ueS2, ['label' => 'Renommée']);
        self::assertResponseRedirects('/formations/'.$f->getId().'?focus='.$ueS2);
        self::assertSame(7, $this->ueCount($f->getId(), $s1));
        $this->em->clear();
        self::assertSame('Renommée', $this->em->find(Formation::class, $f->getId())->getArbre()[0]['children'][1]['children'][0]['label'] ?? null);

        // en revanche, aggraver l'écart est refusé
        $this->add($f, $s1);
        self::assertSame(7, $this->ueCount($f->getId(), $s1));
    }

    public function testUndoRedoAndRestoreAreNeverBlocked(): void
    {
        [$f, $s1] = $this->licence(6);
        $maquette = static::getContainer()->get(Maquette::class);
        $doc = $maquette->open($f);
        $doc->addNode($s1, 'ue');
        $doc->reindex();
        $overSnapshot = [
            'name' => $f->getName(), 'diplome' => $f->getDiplome(), 'avecParcours' => false, 'structure' => $f->getStructure(),
            'parametres' => [], 'dataParcours' => [], 'arbre' => $doc->dumpTree(),
        ];
        $revision = new FormationRevision($f, 'Hors bornes', $overSnapshot);
        $this->em->persist($revision);
        $this->em->flush();

        $maquette->restore($f, $revision);
        self::assertCount(7, $maquette->open($f)->node($s1)->getChildren());

        // undo : ramène la révision créée par la restauration (état à 6 UE), redo : retour à 7 UE hors bornes
        self::assertTrue($maquette->undo($f));
        self::assertTrue($maquette->redo($f));
        self::assertCount(7, $maquette->open($f)->node($s1)->getChildren());
    }

    public function testFormationWithoutTypeIsNotChecked(): void
    {
        $f = $this->em->getRepository(Formation::class)->findOneBy(['name' => 'Certificat Data Analyst (6 semaines)']);
        self::assertNotNull($f);
        self::assertNull($f->getTypeDiplome());
        $maquette = static::getContainer()->get(Maquette::class);
        $doc = $maquette->open($f);
        $parent = $doc->roots[0]->nid;
        for ($i = 0; $i < 20; ++$i) {
            $doc->addNode($parent, 'ue');
        }
        $maquette->save($f, $doc);

        self::assertGreaterThanOrEqual(20, \count($maquette->open($f)->node($parent)->getChildren()));
    }
}
