<?php

declare(strict_types=1);

namespace App\Tests\Diplome;

use App\Diplome\Spec\VersionConfig;
use App\Entity\TypeDiplome;
use App\Tests\Support\DatabaseTestCase;

final class DemoTypesTest extends DatabaseTestCase
{
    private function config(string $key): VersionConfig
    {
        $t = $this->em->getRepository(TypeDiplome::class)->findOneByKey($key);
        self::assertNotNull($t, $key);
        self::assertSame('2026-2027', $t->latestVersion()?->getAnneeUniversitaire());

        return $t->latestVersion()->config();
    }

    public function testDemoTypesExistWithTheirDistinctiveSettings(): void
    {
        $l = $this->config('L');
        self::assertSame(['min' => 3, 'max' => 4], $l->structure->duration->bound->toArray());
        self::assertSame(['min' => 3, 'max' => 6], $l->structure->levels[2]->count->toArray());
        self::assertSame(['Bloc', 'Compétence'], $l->bccLevels);
        self::assertSame(['CT', 'CC_CT', 'CC', 'CCI'], $l->mccc['ec']['types']);

        $lp = $this->config('LP');
        self::assertSame(5, $lp->structure->levels[1]->startAt);
        self::assertCount(1, $lp->structure->imposed);
        self::assertSame('lp-projet-stage', $lp->structure->imposed[0]->uid);
        self::assertSame('master', $lp->mccc['ec']['profile']);

        self::assertTrue($this->config('M')->structure->duration->bound->isExact());

        $m2e = $this->config('M2E');
        self::assertSame([], array_filter($m2e->structure->levels, static fn ($lv) => 'semestre' === $lv->typeKey));
        self::assertSame(['Bloc', 'Objectif', 'Attendu'], $m2e->bccLevels);

        $but = $this->config('BUT');
        self::assertSame(['ressource', 'sae', 'stage'], $but->structure->natureKeys());
        self::assertCount(1, $but->structure->rules);
        self::assertSame('ratio', $but->structure->rules[0]->template);
        self::assertSame('stage', $but->structure->imposed[0]->nature);
    }

    public function testDemoTypeLabelsMatchLegacyDiplomeLabels(): void
    {
        $labels = array_map(
            static fn (TypeDiplome $t) => $t->getLabel(),
            $this->em->getRepository(TypeDiplome::class)->findAll(),
        );
        sort($labels);
        self::assertSame(['BUT', 'Licence', 'Licence professionnelle', 'Master', 'Master M2E'], $labels);
    }

    public function testEveryDeclaredNatureHasItsMccc(): void
    {
        foreach ($this->em->getRepository(TypeDiplome::class)->findAll() as $type) {
            $config = $type->latestVersion()->config();
            foreach ($config->structure->natureKeys() as $nature) {
                self::assertArrayHasKey($nature, $config->mccc, $type->getKey().' : nature « '.$nature.' » sans MCCC');
                self::assertNotEmpty($config->mccc[$nature]['types'], $type->getKey().' : '.$nature);
            }
        }
    }
}
