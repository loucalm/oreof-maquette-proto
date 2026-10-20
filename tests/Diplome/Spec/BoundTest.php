<?php

declare(strict_types=1);

namespace App\Tests\Diplome\Spec;

use App\Diplome\Spec\Bound;
use PHPUnit\Framework\TestCase;

final class BoundTest extends TestCase
{
    public function testExactBoundHasEqualMinAndMax(): void
    {
        self::assertTrue(Bound::exactly(2)->isExact());
        self::assertFalse(new Bound(2, 3)->isExact());
        self::assertFalse(new Bound(null, 3)->isExact());
        self::assertNull(Bound::fromArray(null));
        self::assertEquals(new Bound(3, null), Bound::fromArray(['min' => 3]));
    }

    public function testToArrayOmitsOpenSides(): void
    {
        self::assertSame(['max' => 10], new Bound(null, 10)->toArray());
        self::assertSame(['min' => 2, 'max' => 2], Bound::exactly(2)->toArray());
    }
}
