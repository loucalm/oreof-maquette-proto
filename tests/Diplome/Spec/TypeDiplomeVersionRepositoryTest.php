<?php

declare(strict_types=1);

namespace App\Tests\Diplome\Spec;

use App\Diplome\Spec\VersionConfig;
use App\Entity\TypeDiplome;
use App\Entity\TypeDiplomeVersion;
use App\Repository\TypeDiplomeVersionRepository;
use App\Tests\Support\DatabaseTestCase;

final class TypeDiplomeVersionRepositoryTest extends DatabaseTestCase
{
    private function type(string ...$years): TypeDiplome
    {
        $t = new TypeDiplome('test_t', 'Test', 'TST');
        foreach ($years as $y) {
            $t->addVersion(new TypeDiplomeVersion($t, $y));
        }
        $this->em->persist($t);
        $this->em->flush();
        $this->em->clear();

        return $this->em->getRepository(TypeDiplome::class)->findOneBy(['key' => 'test_t']);
    }

    private function repo(): TypeDiplomeVersionRepository
    {
        return $this->em->getRepository(TypeDiplomeVersion::class);
    }

    public function testExactYearWins(): void
    {
        $t = $this->type('2024-2025', '2026-2027');
        self::assertSame('2026-2027', $this->repo()->findForYear($t, '2026-2027')?->getAnneeUniversitaire());
    }

    public function testFallsBackToMostRecentEarlier(): void
    {
        $t = $this->type('2024-2025', '2026-2027');
        self::assertSame('2024-2025', $this->repo()->findForYear($t, '2025-2026')?->getAnneeUniversitaire());
        self::assertSame('2026-2027', $this->repo()->findForYear($t, '2028-2029')?->getAnneeUniversitaire());
    }

    public function testNullWhenOnlyLaterVersions(): void
    {
        $t = $this->type('2026-2027');
        self::assertNull($this->repo()->findForYear($t, '2025-2026'));
    }

    public function testVersionsSortedAndLatest(): void
    {
        $t = $this->type('2026-2027', '2024-2025');
        self::assertSame(['2024-2025', '2026-2027'], array_map(fn ($v) => $v->getAnneeUniversitaire(), $t->getVersions()));
        self::assertSame(2026, $t->latestVersion()->startYear());
    }

    public function testConfigIsPersistedAsJson(): void
    {
        $t = $this->type('2026-2027');
        $v = $t->latestVersion();
        $v->setConfig(VersionConfig::fromArray(['bccLevels' => ['Bloc'], 'options' => ['quitus' => true]]));
        $this->em->flush();
        $this->em->clear();

        $reloaded = $this->em->getRepository(TypeDiplome::class)->findOneByKey('test_t')->latestVersion();
        self::assertTrue($reloaded->config()->options['quitus']);
        self::assertSame(['Bloc'], $reloaded->config()->bccLevels);
    }
}
