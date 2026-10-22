<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Diplome\WizardDraft;
use App\Entity\NodeType;
use App\Entity\TypeDiplome;
use App\Tests\Support\ResetsDatabase;
use App\Tests\Support\SameOriginCsrf;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class DiplomeWizardStructureTest extends WebTestCase
{
    use ResetsDatabase;
    use SameOriginCsrf;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->sameOrigin($this->client);
        $this->resetDatabase();
    }

    /** Démarre l'assistant sur la config du type de démo, informations valides. */
    private function startFrom(string $key): array
    {
        $type = $this->em->getRepository(TypeDiplome::class)->findOneBy(['key' => $key]);
        $this->client->request('GET', '/diplomes/nouveau', ['depuis' => $type->latestVersion()->getId()]);
        $crawler = $this->client->request('GET', '/diplomes/nouveau/informations');
        $this->client->submit($crawler->filter('form#wizard-form')->form(['informations[label]' => 'Nouveau', 'informations[sigle]' => 'NVX']));

        return $type->latestVersion()->config()->structure->toArray();
    }

    /** @param array<string, mixed>|null $bound */
    private static function bound(?array $bound): array
    {
        if (null === $bound) {
            return ['mode' => 'none'];
        }
        $min = $bound['min'] ?? null;
        $max = $bound['max'] ?? null;
        if (null !== $min && $min === $max) {
            return ['mode' => 'exact', 'value' => $min];
        }
        if (null !== $min && null !== $max) {
            return ['mode' => 'between', 'value' => $min, 'upper' => $max];
        }

        return null !== $min ? ['mode' => 'min', 'value' => $min] : ['mode' => 'max', 'value' => $max];
    }

    /**
     * Charge utile du formulaire équivalente à une structure existante.
     *
     * @param array<string, mixed> $s
     *
     * @return array<string, mixed>
     */
    private static function payload(array $s): array
    {
        $levels = [];
        foreach ($s['levels'] as $l) {
            $levels[] = ($l['locked'] ? ['locked' => '1'] : []) + [
                'typeKey' => $l['typeKey'],
                'count' => self::bound($l['count']),
                'ectsMode' => null !== $l['ectsSum'] ? 'sum' : (null !== $l['ects'] ? 'node' : 'free'),
                'ectsBound' => self::bound($l['ectsSum'] ?? $l['ects']),
                'startAt' => $l['startAt'],
                'ecLink' => $l['ecLink'],
            ];
        }
        $rules = [];
        foreach ($s['rules'] as $r) {
            $rules[] = ['uid' => $r['uid'], 'template' => $r['template'], 'scopeLevel' => $r['scopeLevel'], 'scopePosition' => $r['scopePosition'] ?? 'all', 'severity' => $r['severity'], 'params_'.$r['template'] => $r['params']];
        }

        $payload = [
            'duration' => ['levelKey' => $s['duration']['levelKey'] ?? '', 'bound' => self::bound($s['duration']['bound'] ?? null)],
            'natures' => array_map(static fn (array $t) => ['key' => $t['key'], 'label' => $t['label']], $s['natures']),
            'levels' => $levels,
            'rules' => $rules,
        ];
        if ($s['parcoursAllowed']) {
            $payload['parcoursAllowed'] = '1';
        }

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    private function submit(array $payload): \Symfony\Component\DomCrawler\Crawler
    {
        return $this->client->request('POST', '/diplomes/nouveau/structure', ['structure' => $payload + ['_token' => $this->formToken()]]);
    }

    /** @return array<string, mixed> */
    private function storedStructure(): array
    {
        return $this->client->getRequest()->getSession()->get(WizardDraft::SESSION_KEY)->config()->structure->toArray();
    }

    /** @param list<string> $chain */
    private function chainPayload(array $chain): array
    {
        $payload = self::payload($this->startFrom('L'));
        $payload['levels'] = array_map(static fn (string $key) => ['typeKey' => $key, 'count' => ['mode' => 'none'], 'ectsMode' => 'free', 'ectsBound' => ['mode' => 'none'], 'startAt' => 1, 'ecLink' => 'contains'], $chain);
        $payload['duration'] = ['levelKey' => '', 'bound' => ['mode' => 'none']];
        $payload['rules'] = [];

        return $payload;
    }

    public function testChainMustEndWithElementLevel(): void
    {
        $crawler = $this->submit($this->chainPayload(['annee', 'semestre', 'ue']));
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('La chaîne doit se terminer par EC', $crawler->text());

        $crawler = $this->submit($this->chainPayload(['annee', 'ec', 'ue']));
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('EC doit être le dernier niveau', $crawler->text());

        $this->submit($this->chainPayload(['annee', 'semestre', 'ue', 'ec']));
        self::assertResponseRedirects('/diplomes/nouveau/formulaires');
    }

    public function testStructureRefusedWhenNoElementLevelIsDesignated(): void
    {
        $payload = $this->chainPayload(['annee', 'semestre', 'ue', 'ec']);
        $ec = $this->em->getRepository(NodeType::class)->findOneBy(['key' => 'ec']);
        $this->client->request('POST', sprintf('/node-types/%d/edit', $ec->getId()), ['label' => 'EC', 'elementLevelForm' => '1']);
        $this->em->clear();
        self::assertFalse($this->em->getRepository(NodeType::class)->findOneBy(['key' => 'ec'])->isElementLevel());

        $crawler = $this->submit($payload);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString("Aucun type de nœud n'est désigné comme niveau élément", $crawler->text());
    }

    public function testVariantsNotProposed(): void
    {
        $variant = (new NodeType('ue_variante', 'UE variante'))->setParent($this->em->getRepository(NodeType::class)->findOneBy(['key' => 'ue']));
        $this->em->persist($variant);
        $this->em->flush();
        static::getContainer()->get('services_resetter')->reset();

        $this->startFrom('L');
        $crawler = $this->client->request('GET', '/diplomes/nouveau/structure');
        self::assertGreaterThan(0, $crawler->filter('select option[value="ue"]')->count());
        self::assertCount(0, $crawler->filter('select option[value="ue_variante"]'));
    }

    public function testEcLinkOnlyAboveElementLevel(): void
    {
        $payload = $this->chainPayload(['annee', 'semestre', 'ue', 'ec']);
        $payload['levels'][1]['ecLink'] = 'coefficient';
        $crawler = $this->submit($payload);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Seul le niveau juste au-dessus d\'EC peut recevoir des coefficients', $crawler->text());

        $payload = $this->chainPayload(['annee', 'ec']);
        $payload['levels'][0]['ecLink'] = 'coefficient';
        $crawler = $this->submit($payload);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Seul le niveau juste au-dessus d\'EC peut recevoir des coefficients', $crawler->text());

        $payload = $this->chainPayload(['annee', 'semestre', 'ue', 'ec']);
        $payload['levels'][2]['ecLink'] = 'coefficient';
        $this->submit($payload);
        self::assertResponseRedirects('/diplomes/nouveau/formulaires');
    }

    public function testLockedLevelRequiresExactCount(): void
    {
        $payload = self::payload($this->startFrom('L'));
        $payload['levels'][2]['locked'] = '1';
        $payload['levels'][2]['count'] = ['mode' => 'between', 'value' => '3', 'upper' => '6'];
        $crawler = $this->submit($payload);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Un niveau verrouillé a un nombre exact', $crawler->text());

        $payload['levels'][2]['count'] = ['mode' => 'exact', 'value' => '3'];
        $this->submit($payload);
        self::assertResponseRedirects('/diplomes/nouveau/formulaires');
    }

    public function testExactlyTwoSemestersIsStoredAsExactBound(): void
    {
        $payload = self::payload($this->startFrom('L'));
        $payload['levels'][1]['count'] = ['mode' => 'exact', 'value' => '2'];

        $this->submit($payload);
        self::assertResponseRedirects('/diplomes/nouveau/formulaires');
        $levels = $this->storedStructure()['levels'];
        self::assertSame('semestre', $levels[1]['typeKey']);
        self::assertSame(['min' => 2, 'max' => 2], $levels[1]['count']);
        $expected = $this->startFrom('L')['levels'];
        self::assertSame($expected[0], $levels[0]);
        self::assertSame($expected[2], $levels[2]);
        self::assertSame($expected[3], $levels[3]);
    }

    public function testRemovingSemesterLevelLikeM2e(): void
    {
        $payload = self::payload($this->startFrom('L'));
        unset($payload['levels'][1]);
        $payload['levels'] = array_values($payload['levels']);
        // sans semestre, les règles ne peuvent plus viser un semestre
        $payload['rules'] = [];

        $this->submit($payload);
        self::assertResponseRedirects('/diplomes/nouveau/formulaires');
        self::assertSame(['annee', 'ue', 'ec'], array_column($this->storedStructure()['levels'], 'typeKey'));

        $crawler = $this->client->request('GET', '/diplomes/nouveau/structure');
        self::assertCount(0, $crawler->filter('[data-preview] [data-node-type="semestre"]'));
        self::assertGreaterThan(0, $crawler->filter('[data-preview] [data-node-type="annee"]')->count());
    }

    public function testAddingRatioRuleStoresTemplateAndParams(): void
    {
        $payload = self::payload($this->startFrom('L'));
        $payload['natures'] = [['key' => 'ec', 'label' => 'ec'], ['key' => 'stage', 'label' => 'stage'], ['key' => '', 'label' => 'SAE']];
        $payload['rules'][] = [
            'uid' => '',
            'template' => 'ratio',
            'scopeLevel' => 'semestre',
            'severity' => 'error',
            'params_ratio' => ['attribute' => 'ects', 'numeratorNature' => 'sae', 'op' => '>=', 'percent' => '40'],
        ];

        $this->submit($payload);
        self::assertResponseRedirects('/diplomes/nouveau/formulaires');
        $rule = $this->storedStructure()['rules'][0];
        self::assertSame('ratio', $rule['template']);
        self::assertSame(['attribute' => 'ects', 'numeratorNature' => 'sae', 'op' => '>=', 'percent' => 40], $rule['params']);
        self::assertSame('semestre', $rule['scopeLevel']);
        self::assertNotSame('', $rule['uid']);
        // un type d'EC ajouté reçoit une clé dérivée de son libellé
        self::assertContains(['key' => 'sae', 'label' => 'SAE'], $this->storedStructure()['natures']);
    }

    public function testUnknownTemplateIsRejected(): void
    {
        $payload = self::payload($this->startFrom('L'));
        $payload['rules'][] = ['uid' => '', 'template' => 'eval', 'scopeLevel' => 'semestre', 'severity' => 'error'];

        $this->submit($payload);
        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $this->storedStructure()['rules']);
    }

    public function testRatioRuleMissingRequiredParamIsRejected(): void
    {
        $payload = self::payload($this->startFrom('L'));
        $payload['rules'][] = ['uid' => '', 'template' => 'ratio', 'scopeLevel' => 'semestre', 'severity' => 'error', 'params_ratio' => ['attribute' => 'ects', 'op' => '>=']];

        $this->submit($payload);
        self::assertResponseStatusCodeSame(422);
    }

    public function testPreviewShowsMinimalTree(): void
    {
        $this->startFrom('L');
        $crawler = $this->client->request('GET', '/diplomes/nouveau/structure');
        self::assertResponseIsSuccessful();
        self::assertCount(3, $crawler->filter('[data-preview] [data-node-type="annee"]'));
        self::assertCount(6, $crawler->filter('[data-preview] [data-node-type="semestre"]'));
    }

    public function testButStageObligationsAreRules(): void
    {
        $structure = $this->startFrom('BUT');
        $crawler = $this->client->request('GET', '/diplomes/nouveau/structure');
        self::assertCount(0, $crawler->filter('[name$="[params_required_element][typeKey]"]'), 'le gabarit « élément imposé » a disparu');
        self::assertCount(0, $crawler->filter('[name^="structure[imposed]"]'));

        $this->submit(self::payload($structure));
        self::assertResponseRedirects('/diplomes/nouveau/formulaires');
        $stored = $this->storedStructure();
        self::assertArrayNotHasKey('imposed', $stored);
        $stages = array_values(array_filter($stored['rules'], static fn (array $r) => 'min_element_type' === $r['template']));
        self::assertSame(['2', '4', '6'], array_column($stages, 'scopePosition'));
        self::assertSame(['nature' => 'stage', 'min' => 1], $stages[0]['params']);
    }

    public function testBetweenBoundWithInvertedValuesIsRejected(): void
    {
        $payload = self::payload($this->startFrom('L'));
        $payload['duration']['bound'] = ['mode' => 'between', 'value' => '4', 'upper' => '3'];

        $this->submit($payload);
        self::assertResponseStatusCodeSame(422);
    }

    public function testDurationNotDivisibleIsRefused(): void
    {
        $start = $this->startFrom('L');
        $payload = self::payload($start);
        $payload['duration'] = ['levelKey' => 'semestre', 'bound' => self::bound(['min' => 5, 'max' => 5])];

        $crawler = $this->submit($payload);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('La durée (5) n\'est pas un multiple de 2 semestres par année', $crawler->text());
        self::assertSame($start['duration'], $this->storedStructure()['duration']);
    }

    public function testSummaryListsRules(): void
    {
        $this->startFrom('BUT');
        $crawler = $this->client->request('GET', '/diplomes/nouveau/recapitulatif');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Au moins N EC d\'un type', $crawler->text());
        self::assertStringContainsString('Part d\'un attribut pour une nature d\'élément', $crawler->text());
        self::assertStringNotContainsString('Éléments imposés', $crawler->text());
    }

    public function testUndeclaredNatureIsRejected(): void
    {
        $payload = self::payload($this->startFrom('L'));
        $payload['rules'][] = ['uid' => '', 'template' => 'min_element_type', 'scopeLevel' => 'semestre', 'scopePosition' => 'last', 'severity' => 'error', 'params_min_element_type' => ['nature' => 'sae', 'min' => '1']];
        $this->submit($payload);
        self::assertResponseStatusCodeSame(422);

        $payload = self::payload($this->startFrom('L'));
        $payload['rules'][] = ['uid' => '', 'template' => 'ratio', 'scopeLevel' => 'semestre', 'severity' => 'error', 'params_ratio' => ['attribute' => 'ects', 'numeratorNature' => 'sae', 'op' => '>=', 'percent' => '40']];
        $this->submit($payload);
        self::assertResponseStatusCodeSame(422);
    }

    public function testLevelsAreOrderedByTheirPosition(): void
    {
        $payload = self::payload($this->startFrom('L'));
        // UE et semestre échangés par leur rang (déplacement des blocs dans la page)
        foreach ([0, 2, 1, 3] as $i => $position) {
            $payload['levels'][$i]['position'] = (string) $position;
        }
        $this->submit($payload);

        self::assertResponseRedirects();
        self::assertSame(['annee', 'ue', 'semestre', 'ec'], array_column($this->storedStructure()['levels'], 'typeKey'));
    }

    public function testLevelsHaveNoImposedElementControls(): void
    {
        $this->startFrom('BUT');
        $crawler = $this->client->request('GET', '/diplomes/nouveau/structure');

        self::assertCount(4, $crawler->filter('[data-tree-level]'));
        self::assertCount(0, $crawler->filter('[data-imposed-row], [data-imposed-toggle], [data-imposed-panel]'));
    }

    public function testButDeclaresItsStageType(): void
    {
        $structure = $this->startFrom('BUT');
        self::assertContains('stage', array_column($structure['natures'], 'key'));
    }

    public function testSubmittingLpUnchangedKeepsEverything(): void
    {
        $structure = $this->startFrom('LP');
        $this->submit(self::payload($structure));
        self::assertResponseRedirects('/diplomes/nouveau/formulaires');
        self::assertSame($structure, $this->storedStructure());
    }

    public function testRemovingUsedEcTypeIsRefused(): void
    {
        $payload = self::payload($this->startFrom('LP'));
        $payload['rules'][] = ['uid' => '', 'template' => 'min_element_type', 'scopeLevel' => 'semestre', 'scopePosition' => 'last', 'severity' => 'error', 'params_min_element_type' => ['nature' => 'stage', 'min' => '1']];
        $this->submit($payload);
        self::assertResponseRedirects('/diplomes/nouveau/formulaires');
        self::assertSame('last', $this->storedStructure()['rules'][0]['scopePosition']);

        $payload = self::payload($this->storedStructure());
        $payload['natures'] = [['key' => 'ec', 'label' => 'ec']];
        $crawler = $this->submit($payload);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Le type Stage est utilisé par une règle', $crawler->text());
    }

    public function testTreeShowsLevelsInOrder(): void
    {
        $this->startFrom('L');
        $crawler = $this->client->request('GET', '/diplomes/nouveau/structure');
        self::assertResponseIsSuccessful();

        self::assertSame(['annee', 'semestre', 'ue', 'ec'], $crawler->filter('[data-tree-level]')->each(static fn ($n) => $n->attr('data-tree-level')));
        $semestre = $crawler->filter('[data-tree-level="semestre"]')->text();
        self::assertStringContainsString('× 2', $semestre);
        self::assertStringContainsString('30 ECTS', $semestre);
        self::assertStringContainsString('3 à 6', $crawler->filter('[data-tree-level="ue"]')->text());
        self::assertCount(1, $crawler->filter('[data-tree-level="ec"][data-element-level]'));
        self::assertCount(1, $crawler->filter('[data-tree-level][data-element-level]'));
        // le premier niveau affiche son nombre déduit de la durée (3 à 4 années)
        self::assertStringContainsString('3 à 4', $crawler->filter('[data-tree-level="annee"]')->text());
        // aucun panneau ouvert au chargement
        self::assertCount(0, $crawler->filter('[data-level-panel]:not([hidden])'));
        self::assertCount(4, $crawler->filter('[data-level-panel]'));
    }

    public function testDurationListsOnlyTemporalLevels(): void
    {
        $temporal = [];
        foreach ($this->em->getRepository(NodeType::class)->findAll() as $type) {
            if ($type->isTemporel()) {
                $temporal[] = $type->getKey();
            }
        }
        foreach (['L' => ['annee', 'semestre', 'ue', 'ec'], 'M2E' => ['annee', 'bloc_formation', 'ec']] as $key => $chain) {
            $this->startFrom($key);
            $crawler = $this->client->request('GET', '/diplomes/nouveau/structure');
            $options = $crawler->filter('select[name="structure[duration][levelKey]"] option')->each(static fn ($n) => $n->attr('value'));
            self::assertSame(array_values(array_intersect($chain, $temporal)), $options, $key);
            self::assertNotSame([], $options);
        }
    }

    public static function demoTypes(): iterable
    {
        foreach (['L', 'LP', 'M', 'M2E', 'BUT'] as $key) {
            yield $key => [$key];
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('demoTypes')]
    public function testRoundTripKeepsStructure(string $key): void
    {
        $structure = $this->startFrom($key);
        $crawler = $this->client->request('GET', '/diplomes/nouveau/structure');
        $this->client->submit($crawler->filter('form#wizard-form')->form());

        self::assertResponseRedirects('/diplomes/nouveau/formulaires');
        self::assertSame($structure, $this->storedStructure());
    }

    public function testLockAndEcLinkAreSaved(): void
    {
        $payload = self::payload($this->startFrom('L'));
        $payload['levels'][2]['locked'] = '1';
        $payload['levels'][2]['count'] = ['mode' => 'exact', 'value' => '4'];
        $payload['levels'][2]['ecLink'] = 'coefficient';
        $this->submit($payload);
        self::assertResponseRedirects('/diplomes/nouveau/formulaires');

        $ue = $this->storedStructure()['levels'][2];
        self::assertTrue($ue['locked']);
        self::assertSame(['min' => 4, 'max' => 4], $ue['count']);
        self::assertSame('coefficient', $ue['ecLink']);

        $crawler = $this->client->request('GET', '/diplomes/nouveau/structure');
        self::assertCount(1, $crawler->filter('[data-tree-level="ue"] [data-icon="locked"]:not([hidden])'));
        self::assertCount(1, $crawler->filter('[data-tree-level="semestre"] [data-icon="editable"]:not([hidden])'));
        self::assertStringContainsString('coef. vers les UE', $crawler->filter('[data-tree-level="ec"]')->text());
    }

    public function testEctsModeMapsToBounds(): void
    {
        $payload = self::payload($this->startFrom('L'));
        $payload['levels'][1]['ectsMode'] = 'sum';
        $payload['levels'][1]['ectsBound'] = ['mode' => 'exact', 'value' => '30'];
        $payload['levels'][2]['ectsMode'] = 'node';
        $payload['levels'][2]['ectsBound'] = ['mode' => 'max', 'value' => '10'];
        $payload['levels'][3]['ectsMode'] = 'free';
        $payload['levels'][3]['ectsBound'] = ['mode' => 'exact', 'value' => '5'];
        $this->submit($payload);
        self::assertResponseRedirects('/diplomes/nouveau/formulaires');

        $levels = $this->storedStructure()['levels'];
        self::assertSame(['min' => 30, 'max' => 30], $levels[1]['ectsSum']);
        self::assertNull($levels[1]['ects']);
        self::assertSame(['max' => 10], $levels[2]['ects']);
        self::assertNull($levels[2]['ectsSum']);
        self::assertNull($levels[3]['ects']);
        self::assertNull($levels[3]['ectsSum']);

        $crawler = $this->client->request('GET', '/diplomes/nouveau/structure');
        self::assertStringContainsString('≤ 10 ECTS', $crawler->filter('[data-tree-level="ue"]')->text());
    }

    public function testEcTypesAreTagsWithLinksToFormsAndMccc(): void
    {
        $this->startFrom('LP');
        $crawler = $this->client->request('GET', '/diplomes/nouveau/structure');

        $tags = $crawler->filter('[data-level-panel] [data-ec-type]');
        self::assertCount(3, $tags);
        self::assertStringContainsString('3 types', $crawler->filter('[data-tree-level="ec"]')->text());
        // même navigation que la barre latérale : l'étape courante est soumise vers l'étape visée
        self::assertCount(3, $tags->filter('button[name="goto"][value="formulaires"]'));
        self::assertCount(3, $tags->filter('button[name="goto"][value="mccc"]'));
    }

    public function testRenamingEcTypeKeepsItsKey(): void
    {
        $payload = self::payload($this->startFrom('LP'));
        $payload['natures'][2]['label'] = 'Stage en entreprise';
        $this->submit($payload);
        self::assertResponseRedirects('/diplomes/nouveau/formulaires');
        self::assertContains(['key' => 'stage', 'label' => 'Stage en entreprise'], $this->storedStructure()['natures']);
    }
}
