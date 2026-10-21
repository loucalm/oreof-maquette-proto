<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Diplome\Spec\VersionConfig;
use App\Diplome\StructureInstantiator;
use App\Diplome\TypeDiplomeResolver;
use App\Entity\Formation;
use App\Entity\TypeDiplome;
use App\Maquette\Doc\MaquetteDoc;
use App\Maquette\Doc\TreeNode;
use App\Maquette\Maquette;
use App\Tests\Support\ResetsDatabase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Un nouvel hôte d'élément imposé `all` (semestre ajouté ou dupliqué) reçoit l'élément d'office :
 * le garde ne refuse pas l'ajout.
 */
final class ImposedEveryHostEditorTest extends WebTestCase
{
    use ResetsDatabase;

    private const UID = 'lp-rentree';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->resetDatabase();
    }

    private function create(): int
    {
        $formation = (new Formation('LP rentrée'))->setTypeDiplome($this->em->getRepository(TypeDiplome::class)->findOneByKey('LP'));
        $this->em->persist($formation);
        $this->em->flush();
        $version = static::getContainer()->get(TypeDiplomeResolver::class)->versionFor($formation);
        $config = $version->config()->toArray();
        $config['structure']['imposed'][] = ['uid' => self::UID, 'typeKey' => 'ue', 'label' => 'UE de rentrée', 'parentLevel' => 'semestre', 'position' => 'all', 'locked' => ['delete']];
        $version->setConfig(VersionConfig::fromArray($config));
        $this->em->flush();

        $maquette = static::getContainer()->get(Maquette::class);
        $doc = $maquette->open($formation);
        static::getContainer()->get(StructureInstantiator::class)->instantiate($doc, $version, 1, false);
        $maquette->save($formation, $doc, 'Création');

        return $formation->getId();
    }

    private function doc(int $fid): MaquetteDoc
    {
        $this->client->getKernel()->shutdown();
        $this->client->getKernel()->boot();
        $this->em = static::getContainer()->get('doctrine')->getManager();

        return static::getContainer()->get(Maquette::class)->open($this->em->find(Formation::class, $fid));
    }

    /** @return list<TreeNode> */
    private function ofType(MaquetteDoc $doc, string $typeKey): array
    {
        return array_values(array_filter($doc->allNodes(), static fn (TreeNode $n) => $n->typeKey === $typeKey));
    }

    private function imposedCount(TreeNode $host): int
    {
        return \count(array_filter($host->getChildren(), static fn (TreeNode $n) => self::UID === $n->getAttribute('_imposed')));
    }

    public function testAddedAndDuplicatedSemestersGetTheElementAndAreNotRefused(): void
    {
        $fid = $this->create();

        $this->client->request('POST', '/formations/'.$fid.'/nodes', ['parentId' => '', 'typeKey' => 'annee']);
        self::assertResponseRedirects();
        $annee = $this->ofType($this->doc($fid), 'annee')[1];

        $this->client->request('POST', '/formations/'.$fid.'/nodes', ['parentId' => $annee->nid, 'typeKey' => 'semestre']);
        self::assertResponseRedirects();
        $semestres = $this->ofType($this->doc($fid), 'semestre');
        self::assertCount(3, $semestres, 'le semestre ajouté n\'est pas refusé par le garde');
        self::assertSame(1, $this->imposedCount($semestres[2]));

        $this->client->request('POST', '/formations/'.$fid.'/nodes/'.$semestres[2]->nid.'/duplicate');
        self::assertResponseRedirects();
        $semestres = $this->ofType($this->doc($fid), 'semestre');
        self::assertCount(4, $semestres);
        foreach ($semestres as $semestre) {
            self::assertSame(1, $this->imposedCount($semestre), 'jamais de doublon');
        }
    }
}
