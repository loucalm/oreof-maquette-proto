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
            'forms' => ['formation.organisation' => 'org_licence', 'parcours.presentation' => 'pres_parcours'],
            'mccc' => ['ec' => ['types' => ['CT', 'CC_CT', 'CC', 'CCI'], 'profile' => 'licence']],
            'options' => ['quitus' => true, 'assiduite' => true, 'sansNote' => false],
            'bccLevels' => ['Bloc', 'Compétence'],
            'bccLinkLevel' => 1,
        ];

        self::assertSame($raw, VersionConfig::fromArray($raw)->toArray());
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
