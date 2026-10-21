<?php

declare(strict_types=1);

namespace App\Tests\Diplome\Spec;

use App\Diplome\Spec\VersionConfig;
use PHPUnit\Framework\TestCase;

final class VersionConfigTest extends TestCase
{
    public function testRoundTripKeepsEveryValue(): void
    {
        $raw = [
            'structure' => [
                'levels' => [
                    ['typeKey' => 'annee', 'count' => ['min' => 3, 'max' => 3], 'ects' => null, 'ectsSum' => null, 'startAt' => 1],
                    ['typeKey' => 'semestre', 'count' => ['min' => 2, 'max' => 2], 'ects' => null, 'ectsSum' => ['min' => 30, 'max' => 30], 'startAt' => 1],
                    ['typeKey' => 'ue', 'count' => ['min' => 3, 'max' => 6], 'ects' => ['max' => 10], 'ectsSum' => null, 'startAt' => 1],
                    ['typeKey' => 'ec', 'count' => ['max' => 4], 'ects' => null, 'ectsSum' => null, 'startAt' => 1],
                ],
                'parcoursAllowed' => true,
                'duration' => ['min' => 1, 'max' => 3],
                'natures' => ['ec', 'stage'],
                'imposed' => [[
                    'uid' => 'imp-1', 'typeKey' => 'ec', 'label' => 'Stage', 'parentLevel' => 'semestre',
                    'position' => 'last', 'nature' => 'stage', 'locked' => ['label', 'ects'],
                    'countOverride' => ['min' => 1, 'max' => 1],
                ]],
                'rules' => [[
                    'uid' => 'r-1', 'template' => 'ects_sum', 'params' => ['total' => 180],
                    'scopeLevel' => 'annee', 'severity' => 'warning',
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
}
