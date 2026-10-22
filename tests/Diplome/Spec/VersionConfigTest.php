<?php

declare(strict_types=1);

namespace App\Tests\Diplome\Spec;

use App\Diplome\Spec\LevelSpec;
use App\Diplome\Spec\StructureSpec;
use App\Diplome\Spec\VersionConfig;
use PHPUnit\Framework\TestCase;

final class VersionConfigTest extends TestCase
{
    public function testRoundTripKeepsEveryValue(): void
    {
        $raw = [
            'structure' => [
                'levels' => [
                    ['typeKey' => 'annee', 'count' => ['min' => 3, 'max' => 3], 'ects' => null, 'ectsSum' => null, 'startAt' => 1, 'locked' => false, 'ecLink' => 'contains'],
                    ['typeKey' => 'semestre', 'count' => ['min' => 2, 'max' => 2], 'ects' => null, 'ectsSum' => ['min' => 30, 'max' => 30], 'startAt' => 1, 'locked' => false, 'ecLink' => 'contains'],
                    ['typeKey' => 'ue', 'count' => ['min' => 3, 'max' => 6], 'ects' => ['max' => 10], 'ectsSum' => null, 'startAt' => 1, 'locked' => false, 'ecLink' => 'contains'],
                    ['typeKey' => 'ec', 'count' => ['max' => 4], 'ects' => null, 'ectsSum' => null, 'startAt' => 1, 'locked' => false, 'ecLink' => 'contains'],
                ],
                'parcoursAllowed' => true,
                'duration' => ['levelKey' => 'annee', 'bound' => ['min' => 1, 'max' => 3]],
                'natures' => [['key' => 'ec', 'label' => 'EC'], ['key' => 'stage', 'label' => 'Stage']],
                'imposed' => [[
                    'uid' => 'imp-1', 'typeKey' => 'ec', 'label' => 'Stage', 'parentLevel' => 'semestre',
                    'position' => 'last', 'nature' => 'stage', 'locked' => ['label', 'ects'],
                    'countOverride' => ['min' => 1, 'max' => 1],
                ]],
                'rules' => [[
                    'uid' => 'r-1', 'template' => 'ects_sum', 'params' => ['total' => 180],
                    'scopeLevel' => 'annee', 'severity' => 'warning', 'scopePosition' => 'last',
                ]],
            ],
            'canvas' => [
                'formation' => [['key' => 'organisation', 'form' => 'org_licence', 'builtin' => null, 'label' => null, 'hidden' => false]],
                'parcours' => [['key' => 'bcc', 'form' => null, 'builtin' => 'bcc', 'label' => 'BCC', 'hidden' => true]],
            ],
            'mccc' => ['ec' => ['types' => ['CT', 'CC_CT', 'CC', 'CCI'], 'profile' => 'licence']],
            'options' => ['quitus' => true, 'assiduite' => true, 'sansNote' => false],
            'bccLevels' => ['Bloc', 'Compétence'],
            'bccLinkLevel' => 1,
        ];

        self::assertSame($raw, VersionConfig::fromArray($raw)->toArray());
    }

    public function testLegacyFormsSurviveUntilACanvasIsWritten(): void
    {
        $c = VersionConfig::fromArray(['forms' => ['formation.organisation' => 'org_licence']]);

        self::assertNull($c->canvas);
        self::assertSame(['formation.organisation' => 'org_licence'], $c->legacyForms);
        // enregistrer un autre onglet de la version ne perd pas l'ancien choix de formulaires
        self::assertSame(['formation.organisation' => 'org_licence'], VersionConfig::fromArray($c->toArray())->legacyForms);

        $withCanvas = VersionConfig::fromArray(['canvas' => ['formation' => [], 'parcours' => []], 'forms' => ['formation.organisation' => 'org_licence']]);
        self::assertSame([], $withCanvas->legacyForms);
        self::assertArrayNotHasKey('forms', $withCanvas->toArray());
    }

    public function testMissingKeysFallBackToDefaults(): void
    {
        $c = VersionConfig::fromArray([]);

        self::assertSame([], $c->structure->levels);
        self::assertSame(['quitus' => false, 'assiduite' => false, 'sansNote' => false], $c->options);
        self::assertSame(0, $c->bccLinkLevel);
    }

    public function testBccLinkLevelDefaultsToLastBccLevel(): void
    {
        self::assertSame(2, VersionConfig::fromArray(['bccLevels' => ['A', 'B', 'C']])->bccLinkLevel);
    }

    public function testNewShapeRoundTrips(): void
    {
        $raw = [
            'levels' => [
                ['typeKey' => 'annee', 'count' => null, 'ects' => null, 'ectsSum' => null, 'startAt' => 1, 'locked' => false, 'ecLink' => 'contains'],
                ['typeKey' => 'semestre', 'count' => null, 'ects' => null, 'ectsSum' => null, 'startAt' => 1, 'locked' => true, 'ecLink' => 'contains'],
            ],
            'parcoursAllowed' => false,
            'duration' => ['levelKey' => 'semestre', 'bound' => ['min' => 6, 'max' => 6]],
            'natures' => [['key' => 'stage', 'label' => 'Stage']],
            'imposed' => [],
            'rules' => [],
        ];
        $spec = StructureSpec::fromArray(['levels' => [['typeKey' => 'annee'], ['typeKey' => 'semestre', 'locked' => true, 'ecLink' => 'contains']], 'duration' => $raw['duration'], 'natures' => $raw['natures']]);

        self::assertSame($raw, $spec->toArray());
        self::assertTrue($spec->toArray()['levels'][1]['locked']);
        self::assertSame('semestre', $spec->duration->levelKey);
        self::assertSame(['key' => 'stage', 'label' => 'Stage'], $spec->toArray()['natures'][0]);
    }

    public function testLegacyShapeLoads(): void
    {
        $spec = StructureSpec::fromArray(['levels' => [['typeKey' => 'annee']], 'duration' => ['min' => 3, 'max' => 4], 'natures' => ['ec', 'stage']]);

        self::assertSame('annee', $spec->duration->levelKey);
        self::assertSame(3, $spec->duration->bound->min);
        self::assertSame(4, $spec->duration->bound->max);
        self::assertSame(['ec', 'stage'], $spec->natureKeys());
        self::assertSame('stage', $spec->natures[1]->label);
        self::assertFalse($spec->levels[0]->locked);
        self::assertSame(LevelSpec::EC_CONTAINS, $spec->levels[0]->ecLink);
    }
}
