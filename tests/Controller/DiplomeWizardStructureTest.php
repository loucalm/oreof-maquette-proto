<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Diplome\WizardDraft;
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
                'ects' => self::bound($l['ects']),
                'ectsSum' => self::bound($l['ectsSum']),
                'startAt' => $l['startAt'],
                'ecLink' => $l['ecLink'],
            ];
        }
        $imposed = [];
        foreach ($s['imposed'] as $i) {
            $imposed[] = ['uid' => $i['uid'], 'typeKey' => $i['typeKey'], 'label' => $i['label'], 'position' => $i['position'], 'nature' => $i['nature'], 'locked' => $i['locked'], 'countOverride' => self::bound($i['countOverride'])];
        }
        $rules = [];
        foreach ($s['rules'] as $r) {
            $rules[] = ['uid' => $r['uid'], 'template' => $r['template'], 'scopeLevel' => $r['scopeLevel'], 'severity' => $r['severity'], 'params_'.$r['template'] => array_diff_key($r['params'], ['imposed' => 1])]; // le champ interne n'est jamais envoyé
        }

        $payload = [
            'duration' => ['levelKey' => $s['duration']['levelKey'] ?? '', 'bound' => self::bound($s['duration']['bound'] ?? null)],
            'natures' => implode(', ', array_column($s['natures'], 'key')),
            'levels' => $levels,
            'imposed' => $imposed,
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
        // sans semestre, les éléments de niveau supérieur ne peuvent plus viser un semestre
        $payload['imposed'] = [];
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
        $payload['natures'] = 'ec, sae';
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

    public function testImposedElementUsesLevelAboveAsParentAndHidesInternalParam(): void
    {
        $structure = $this->startFrom('BUT');
        $crawler = $this->client->request('GET', '/diplomes/nouveau/structure');
        self::assertCount(1, $crawler->filter('[name$="[params_required_element][typeKey]"]'), 'le gabarit « élément imposé » est proposé');
        self::assertCount(0, $crawler->filter('[name$="[params_required_element][imposed]"]'), 'le paramètre interne « imposed » ne doit pas être proposé');

        $this->submit(self::payload($structure));
        self::assertResponseRedirects('/diplomes/nouveau/formulaires');
        $imposed = $this->storedStructure()['imposed'][0];
        self::assertSame('but-stage', $imposed['uid']);
        self::assertSame('ue', $imposed['parentLevel']);
        self::assertSame('stage', $imposed['nature']);
    }

    public function testImposedElementOutsideChainIsRejected(): void
    {
        $payload = self::payload($this->startFrom('L'));
        $payload['imposed'][] = ['uid' => '', 'typeKey' => 'bloc_formation', 'label' => 'X', 'position' => 'last', 'nature' => '', 'countOverride' => ['mode' => 'none']];

        $this->submit($payload);
        self::assertResponseStatusCodeSame(422);
    }

    public function testBetweenBoundWithInvertedValuesIsRejected(): void
    {
        $payload = self::payload($this->startFrom('L'));
        $payload['duration']['bound'] = ['mode' => 'between', 'value' => '4', 'upper' => '3'];

        $this->submit($payload);
        self::assertResponseStatusCodeSame(422);
    }

    public function testSummaryListsImposedElementsAndRules(): void
    {
        $this->startFrom('BUT');
        $crawler = $this->client->request('GET', '/diplomes/nouveau/recapitulatif');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Stage', $crawler->text());
        self::assertStringContainsString('Part d\'un attribut pour une nature d\'élément', $crawler->text());
        self::assertStringNotContainsString('Identifiant de l\'élément imposé', $crawler->text());
    }

    public function testUndeclaredNatureIsRejected(): void
    {
        $payload = self::payload($this->startFrom('L'));
        $payload['imposed'][] = ['uid' => '', 'typeKey' => 'ue', 'label' => 'X', 'position' => 'last', 'nature' => 'sae', 'countOverride' => ['mode' => 'none']];
        $this->submit($payload);
        self::assertResponseStatusCodeSame(422);

        $payload = self::payload($this->startFrom('L'));
        $payload['rules'][] = ['uid' => '', 'template' => 'ratio', 'scopeLevel' => 'semestre', 'severity' => 'error', 'params_ratio' => ['attribute' => 'ects', 'numeratorNature' => 'sae', 'op' => '>=', 'percent' => '40']];
        $this->submit($payload);
        self::assertResponseStatusCodeSame(422);
    }

    public function testImposedPositionAndNatureAreChoices(): void
    {
        $this->startFrom('BUT');
        $crawler = $this->client->request('GET', '/diplomes/nouveau/structure');

        $positions = $crawler->filter('select[name="structure[imposed][0][position]"] option')->each(static fn ($o) => $o->attr('value'));
        self::assertSame(['all', 'first', 'last', '1', '2'], \array_slice($positions, 0, 5));
        $natures = array_values(array_filter($crawler->filter('select[name="structure[imposed][0][nature]"] option')->each(static fn ($o) => (string) $o->attr('value'))));
        self::assertSame(['ressource', 'sae', 'stage'], $natures);

        $payload = self::payload($this->startFrom('BUT'));
        $payload['imposed'][0]['position'] = 'n’importe quoi';
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
        $payload['imposed'] = [];
        $this->submit($payload);

        self::assertResponseRedirects();
        self::assertSame(['annee', 'ue', 'semestre', 'ec'], array_column($this->storedStructure()['levels'], 'typeKey'));
    }

    public function testImposedElementsAreShownInsideTheirLevel(): void
    {
        $this->startFrom('BUT');
        $crawler = $this->client->request('GET', '/diplomes/nouveau/structure');

        $blocks = $crawler->filter('[data-levels-target="list"] > [data-level-block]');
        $byType = [];
        $blocks->each(static function ($b) use (&$byType): void {
            $byType[$b->filter('[data-level-type] option[selected]')->attr('value')] = $b->filter('[data-imposed-row]')->count();
        });
        // la punaise d'un niveau rend ce niveau imposé : l'EC « Stage » est sous la ligne EC
        self::assertSame(1, $byType['ec']);
        self::assertSame(0, $byType['ue']);
        self::assertSame('ec', $crawler->filter('[data-imposed-row] [data-imposed-type]')->attr('value'));
        // le premier niveau n'a pas de parent : il ne peut pas être imposé
        $first = $crawler->filter('[data-levels-target="list"] > [data-level-block]')->first();
        self::assertNotNull($first->filter('[data-imposed-toggle]')->attr('disabled'));
        // un EC imposé est une feuille : pas de surcharge du nombre de ses enfants
        self::assertCount(0, $crawler->filter('[data-imposed-row] [data-imposed-children]:not([hidden])'));
    }

    public function testButDeclaresItsImposedNature(): void
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

    public function testInternalRuleParamSurvivesSubmission(): void
    {
        $structure = $this->startFrom('L');
        $this->submit(self::payload($structure));
        $session = $this->client->getRequest()->getSession();
        $draft = $session->get(WizardDraft::SESSION_KEY);
        $config = $draft->configData;
        $config['structure']['rules'] = [['uid' => 'r-imp', 'template' => 'required_element', 'params' => ['typeKey' => 'ec', 'imposed' => 'abc'], 'scopeLevel' => 'ue', 'severity' => 'error']];
        $draft->configData = $config;
        $session->set(WizardDraft::SESSION_KEY, $draft);
        $session->save();

        $this->submit(self::payload($draft->config()->structure->toArray()));
        self::assertResponseRedirects('/diplomes/nouveau/formulaires');
        self::assertSame(['typeKey' => 'ec', 'imposed' => 'abc'], $this->storedStructure()['rules'][0]['params']);
    }
}
