<?php

declare(strict_types=1);

namespace App\Tests\Diplome;

use App\Diplome\Spec\LevelSpec;
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
        self::assertSame('master', $lp->mccc['ec']['profile']);

        self::assertTrue($this->config('M')->structure->duration->bound->isExact());

        $m2e = $this->config('M2E');
        self::assertSame([], array_filter($m2e->structure->levels, static fn ($lv) => 'semestre' === $lv->typeKey));
        self::assertSame(['Bloc', 'Objectif', 'Attendu'], $m2e->bccLevels);

    }

    public function testLpObligations(): void
    {
        $s = $this->config('LP')->structure;
        $labels = array_column(array_map(static fn ($t) => $t->toArray(), $s->natures), 'label', 'key');
        self::assertSame('Projet tutoré', $labels['projet_tutore'] ?? null);
        self::assertSame('Stage', $labels['stage'] ?? null);

        $obligations = array_values(array_filter($s->rules, static fn ($r) => 'min_element_type' === $r->template));
        self::assertCount(2, $obligations);
        self::assertEqualsCanonicalizing(['projet_tutore', 'stage'], array_map(static fn ($r) => $r->params['nature'], $obligations));
        foreach ($obligations as $rule) {
            self::assertSame('semestre', $rule->scopeLevel);
            self::assertSame('last', $rule->scopePosition);
        }
        self::assertArrayNotHasKey('imposed', $s->toArray());
    }

    public function testButStructure(): void
    {
        $s = $this->config('BUT')->structure;
        self::assertSame('semestre', $s->duration->levelKey);
        self::assertSame(['min' => 6, 'max' => 6], $s->duration->bound->toArray());

        $ue = current(array_filter($s->levels, static fn ($l) => 'ue' === $l->typeKey));
        self::assertSame(['min' => 5, 'max' => 5], $ue->count->toArray());
        self::assertTrue($ue->locked);
        self::assertSame(LevelSpec::EC_COEFFICIENT, $ue->ecLink);

        self::assertSame(
            [['key' => 'ressource', 'label' => 'Ressource'], ['key' => 'sae', 'label' => 'SAÉ'], ['key' => 'stage', 'label' => 'Stage'], ['key' => 'portfolio', 'label' => 'Portfolio']],
            array_map(static fn ($t) => $t->toArray(), $s->natures),
        );

        $stages = array_values(array_filter($s->rules, static fn ($r) => 'min_element_type' === $r->template));
        self::assertSame(['2', '4', '6'], array_map(static fn ($r) => $r->scopePosition, $stages));
        foreach ($stages as $rule) {
            self::assertSame('stage', $rule->params['nature']);
            self::assertSame('semestre', $rule->scopeLevel);
        }
        self::assertCount(1, array_filter($s->rules, static fn ($r) => 'ratio' === $r->template && 'sae' === $r->params['numeratorNature']));
        self::assertArrayNotHasKey('imposed', $s->toArray());
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
