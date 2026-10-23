<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Diplome\StructureInstantiator;
use App\Entity\Formation;
use App\Entity\TypeDiplome;
use App\Maquette\Doc\TreeNode;
use App\Maquette\Maquette;
use App\Tests\Support\ResetsDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class McccExportTest extends WebTestCase
{
    use ResetsDatabase;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->resetDatabase();
    }

    public function testLicenceHasOneSheetPerYearWithItsElementsAndMccc(): void
    {
        $formation = $this->licenceWithOneEc();

        $book = $this->download(sprintf('/formations/%d/export/mccc.xlsx', $formation->getId()));
        self::assertSame(['Année 1', 'Année 2', 'Année 3'], $book->getSheetNames());

        $rows = $this->rows($book->getSheet(0));
        $header = $this->rowWith($rows, 'Intitulé');
        // colonnes MCCC : les types autorisés par la version de la Licence
        foreach (['Contrôle continu intégral (CCI)', 'Contrôle terminal (CT)'] as $label) {
            self::assertContains($label, $header);
        }

        $ec = $this->rowWith($rows, 'Programmation web');
        self::assertContains('Semestre 1', $ec, 'regroupement par semestre puis par UE');
        self::assertContains('WEB-1', $ec);
        self::assertContains('Oral 30 min (40 %) ; Rapport / dossier (60 %)', $ec);
        self::assertSame('Oral 30 min (40 %) ; Rapport / dossier (60 %)', $ec[array_search('Contrôle continu intégral (CCI)', $header, true)]);
        self::assertSame(12, $ec[array_search('CM', $header, true)]);

        $total = $this->rowWith($rows, 'Total Semestre 1');
        self::assertContains(12, $total);
        self::assertNotNull($this->rowWith($rows, 'Total Année 1'));
    }

    public function testSheetsCanFollowAnotherLevel(): void
    {
        $formation = $this->licenceWithOneEc();

        $book = $this->download(sprintf('/formations/%d/export/mccc.xlsx?feuille=semestre', $formation->getId()));
        self::assertCount(6, $book->getSheetNames());
        self::assertSame('Semestre 1', $book->getSheetNames()[0]);
        self::assertNotNull($this->rowWith($this->rows($book->getSheet(0)), 'Programmation web'));
    }

    public function testExportPageOffersTheExcelDownload(): void
    {
        $formation = $this->licenceWithOneEc();

        $crawler = $this->client->request('GET', sprintf('/formations/%d/export', $formation->getId()));
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form[data-mccc-export]');
        self::assertSame(sprintf('/formations/%d/export/mccc.xlsx', $formation->getId()), $form->attr('action'));
        self::assertSame(['annee', 'semestre', 'ue'], $form->filter('select[name=feuille] option')->each(static fn ($o) => $o->attr('value')));
    }

    public function testFormationWithParcoursExportsTheChosenPath(): void
    {
        $formation = $this->em->getRepository(Formation::class)->findOneBy(['avecParcours' => true]);
        self::assertNotNull($formation);
        $crawler = $this->client->request('GET', sprintf('/formations/%d/export', $formation->getId()));
        $options = $crawler->filter('form[data-mccc-export] select[name=parcours] option');
        self::assertGreaterThan(1, $options->count());
        $last = $options->last();

        $book = $this->download(sprintf('/formations/%d/export/mccc.xlsx?parcours=%s', $formation->getId(), $last->attr('value')));
        self::assertNotNull($this->rowWith($this->rows($book->getSheet(0)), $last->text()), 'le chemin de parcours figure dans l\'en-tête');
    }

    private function licenceWithOneEc(): Formation
    {
        $type = $this->em->getRepository(TypeDiplome::class)->findOneByKey('L');
        $formation = new Formation('Licence export');
        $formation->setTypeDiplome($type);
        $this->em->persist($formation);
        $this->em->flush();

        $maquette = static::getContainer()->get(Maquette::class);
        $doc = $maquette->open($formation);
        static::getContainer()->get(StructureInstantiator::class)->instantiate($doc, $type->latestVersion(), 3, false);
        $ue = current(array_filter($doc->allNodes(), static fn (TreeNode $n) => 'ue' === $n->typeKey));
        $ec = $doc->addNode($ue->nid, 'ec', 'Programmation web', 'WEB-1');
        $ec->setAttribute('ects', 4);
        $ec->setAttribute('hours', ['pres' => ['cm' => 12]]);
        $ec->setAttribute('mccc', ['type' => 'CCI', 'evaluations' => [
            ['type' => 'oral', 'weight' => 40, 'duree' => 30],
            ['type' => 'rapport', 'weight' => 60],
        ]]);
        $maquette->save($formation, $doc, 'EC exporté');

        return $formation;
    }

    private function download(string $url): Spreadsheet
    {
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        self::assertStringContainsString('attachment', (string) $this->client->getResponse()->headers->get('Content-Disposition'));

        $file = tempnam(sys_get_temp_dir(), 'mccc');
        file_put_contents($file, $this->client->getInternalResponse()->getContent());
        try {
            return IOFactory::load($file);
        } finally {
            unlink($file);
        }
    }

    /** @return list<list<mixed>> */
    private function rows(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet): array
    {
        return array_map(static fn (array $r) => array_values($r), $sheet->toArray(null, true, false, false));
    }

    /**
     * @param list<list<mixed>> $rows
     *
     * @return list<mixed>|null
     */
    private function rowWith(array $rows, mixed $value): ?array
    {
        foreach ($rows as $row) {
            if (\in_array($value, $row, true)) {
                return $row;
            }
        }

        return null;
    }
}
