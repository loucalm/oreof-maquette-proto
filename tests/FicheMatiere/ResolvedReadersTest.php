<?php

declare(strict_types=1);

namespace App\Tests\FicheMatiere;

use App\Entity\FicheMatiere;
use App\Entity\Formation;
use App\Export\Mccc\McccTableBuilder;
use App\Maquette\Completion;
use App\Maquette\Doc\MaquetteDoc;
use App\Maquette\Doc\TreeNode;
use App\Maquette\Maquette;
use App\Maquette\MaquetteBuilder;
use App\Maquette\MaquetteExporter;
use App\Tests\Support\DatabaseTestCase;
use App\Tests\Support\FormationFactory;

/** Les lecteurs d'ECTS, d'heures et de MCCC voient la valeur résolue (fiche comprise), jamais l'attribut brut. */
final class ResolvedReadersTest extends DatabaseTestCase
{
    private const MCCC = ['type' => 'CT', 'evaluations' => [['type' => 'ecrit', 'weight' => 100, 'duree' => 120]]];

    private Formation $formation;
    private MaquetteDoc $doc;
    private TreeNode $ec;

    protected function setUp(): void
    {
        parent::setUp();
        $factory = FormationFactory::create(static::getContainer());
        $this->formation = $factory->fromType('L', 3, withLeaves: true);
        $this->doc = static::getContainer()->get(Maquette::class)->open($this->formation);
        $this->ec = $factory->first($this->doc, 'ec');
        foreach (['hours', 'ects', 'mccc'] as $key) {
            $this->ec->setAttribute($key, null);
        }
    }

    public function testBuilderTotalsUseFicheValues(): void
    {
        $this->useFiche(['hours' => ['pres' => ['cm' => 10]], 'ects' => 4, 'mccc' => self::MCCC]);

        $view = static::getContainer()->get(MaquetteBuilder::class)->buildSubtree($this->ec->getParent());

        self::assertSame(4.0, $view->totalEcts);
        self::assertSame(10.0, $view->totalHours);
    }

    public function testCompletionCountsFicheValuesAsProvided(): void
    {
        $this->useFiche(['hours' => ['pres' => ['cm' => 10]], 'ects' => 4, 'mccc' => self::MCCC]);

        $keys = array_column(static::getContainer()->get(Completion::class)->missing($this->ec), 'key');

        foreach (['hours', 'ects', 'mccc', 'ficheMatiere'] as $key) {
            self::assertNotContains($key, $keys, $key);
        }
    }

    public function testMcccExportUsesResolvedValues(): void
    {
        $this->useFiche(['hours' => ['pres' => ['cm' => 10]], 'ects' => 4, 'mccc' => self::MCCC]);

        $table = static::getContainer()->get(McccTableBuilder::class)->build($this->formation);

        $line = null;
        foreach ($table->sheets as $sheet) {
            foreach ($sheet->lines as $l) {
                if ('element' === $l['type'] && $l['label'] === $this->ec->getLabel() && ($l['code'] ?? null) === (string) $this->ec->getCode()) {
                    $line = $l;
                    break 2;
                }
            }
        }
        self::assertNotNull($line, 'ligne de l\'EC');
        self::assertSame(4, $line['ects']);
        self::assertSame(10.0, $line['hours']['presCm']);
        self::assertArrayHasKey('CT', $line['mccc']);
    }

    public function testJsonExportCarriesSources(): void
    {
        $this->useFiche(['ects' => 4]);

        $out = static::getContainer()->get(MaquetteExporter::class)->enriched($this->formation);

        $node = $this->find($out['arbre'], $this->ec->getId());
        self::assertNotNull($node);
        self::assertSame(4, $node['attributes']['ects']);
        self::assertSame('fiche', $node['sources']['ects']);
    }

    public function testMcccTypeNotAllowedIsAnAnomaly(): void
    {
        $this->useFiche(['mccc' => ['type' => 'ZZ', 'evaluations' => []]]);

        $missing = static::getContainer()->get(Completion::class)->missing($this->ec);

        self::assertContains(
            ['key' => 'mccc', 'label' => 'type de MCCC non autorisé par le diplôme', 'tab' => 'mccc'],
            $missing,
        );
        // l'export reste produit
        self::assertNotNull(static::getContainer()->get(McccTableBuilder::class)->build($this->formation));
    }

    public function testMcccTypeNotAllowedEvenWhenNotRequired(): void
    {
        $this->useFiche(['mccc' => ['type' => 'ZZ', 'evaluations' => []]]);
        $this->ec->setCapabilityOverrides(['mccc' => false]);
        $completion = static::getContainer()->get(Completion::class);

        $missing = $completion->missing($this->ec);
        $count = $completion->node($this->ec);

        self::assertContains(['key' => 'mccc', 'label' => 'type de MCCC non autorisé par le diplôme', 'tab' => 'mccc'], $missing);
        self::assertGreaterThanOrEqual(0, $count['filled']);
    }

    public function testAllowedMcccTypeIsNotAnAnomaly(): void
    {
        $this->useFiche(['mccc' => self::MCCC]);

        $labels = array_column(static::getContainer()->get(Completion::class)->missing($this->ec), 'label');

        self::assertNotContains('type de MCCC non autorisé par le diplôme', $labels);
    }

    public function testMissingFicheIsReported(): void
    {
        $this->ec->setAttribute('ficheMatiere', 999999);

        $missing = static::getContainer()->get(Completion::class)->missing($this->ec);

        self::assertContains(['key' => 'ficheMatiere', 'label' => 'fiche matière introuvable', 'tab' => 'props'], $missing);
    }

    public function testLegacyLabelCountsAsNoFiche(): void
    {
        $this->ec->setAttribute('ficheMatiere', 'Ancien libellé');

        $missing = static::getContainer()->get(Completion::class)->missing($this->ec);

        self::assertNotContains('fiche matière introuvable', array_column($missing, 'label'));
        self::assertContains('ficheMatiere', array_column($missing, 'key'));
    }

    /** @param array<string, mixed> $defaults */
    private function useFiche(array $defaults): void
    {
        $fiche = (new FicheMatiere())->setFormation($this->formation)->setParcoursNid(null)
            ->setData(['libelle' => 'Algo'])->setDefaults($defaults);
        $this->em->persist($fiche);
        $this->em->flush();
        $this->ec->setAttribute('ficheMatiere', $fiche->getId());
    }

    /**
     * @param list<array<string, mixed>> $nodes
     *
     * @return array<string, mixed>|null
     */
    private function find(array $nodes, string $nid): ?array
    {
        foreach ($nodes as $n) {
            if ($n['nid'] === $nid) {
                return $n;
            }
            if (null !== $found = $this->find($n['children'] ?? [], $nid)) {
                return $found;
            }
        }

        return null;
    }
}
