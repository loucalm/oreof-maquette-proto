<?php

declare(strict_types=1);

namespace App\Tests\Diplome\Spec;

use App\Diplome\Spec\Bound;
use App\Diplome\Spec\DurationMath;
use App\Diplome\Spec\DurationSpec;
use App\Diplome\Spec\LevelSpec;
use App\Diplome\Spec\StructureSpec;
use PHPUnit\Framework\TestCase;

final class DurationMathTest extends TestCase
{
    private static function spec(string $durationLevel, ?Bound $semestres = null): StructureSpec
    {
        return new StructureSpec(
            [
                new LevelSpec('annee'),
                new LevelSpec('semestre', $semestres ?? Bound::exactly(2)),
                new LevelSpec('ue', new Bound(3, 6)),
                new LevelSpec('ec'),
            ],
            false,
            new DurationSpec($durationLevel, Bound::exactly(6)),
        );
    }

    public function testFactorAndFirstLevelCount(): void
    {
        $s = self::spec('semestre');
        self::assertSame(2, DurationMath::factor($s));
        self::assertSame(3, DurationMath::firstLevelCount($s, 6));
    }

    public function testFirstLevelDuration(): void
    {
        $s = self::spec('annee');
        self::assertSame(1, DurationMath::factor($s));
        self::assertSame(3, DurationMath::firstLevelCount($s, 3));
    }

    public function testNotDivisibleThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DurationMath::firstLevelCount(self::spec('semestre'), 5);
    }

    public function testProblems(): void
    {
        $temporal = ['annee', 'semestre'];
        self::assertNull(DurationMath::problem(self::spec('semestre'), $temporal));
        self::assertNull(DurationMath::problem(self::spec('annee'), $temporal));

        self::assertSame(
            'diplome.wizard.structure.error.duration_intermediate_not_exact',
            DurationMath::problem(self::spec('semestre', new Bound(2, 3)), $temporal),
        );
        self::assertSame(
            'diplome.wizard.structure.error.duration_not_divisible',
            DurationMath::problem(self::specWith('semestre', Bound::exactly(5)), $temporal),
        );
        self::assertSame('diplome.wizard.structure.error.duration_level_not_temporal', DurationMath::problem(self::spec('ue'), $temporal));
        self::assertSame('diplome.wizard.structure.error.duration_level_not_in_chain', DurationMath::problem(self::spec('mois'), $temporal));
    }

    private static function specWith(string $level, Bound $duration): StructureSpec
    {
        $s = self::spec($level);

        return new StructureSpec($s->levels, false, new DurationSpec($level, $duration));
    }
}
