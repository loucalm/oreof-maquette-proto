<?php

declare(strict_types=1);

namespace App\Tests\Diplome\Check;

use App\Diplome\Check\ScopeSelector;
use App\Maquette\Doc\TreeNode;
use PHPUnit\Framework\TestCase;

final class ScopeSelectorTest extends TestCase
{
    public function testDisplayedNumber(): void
    {
        $s1 = new TreeNode('a', 'semestre');
        $s2 = new TreeNode('b', 'semestre');
        $selector = new ScopeSelector();

        self::assertSame([$s2], $selector->select([$s1, $s2], '6', 5));
        self::assertSame([$s1], $selector->select([$s1, $s2], '5', 5));
        self::assertSame([$s1], $selector->select([$s1, $s2], 'first', 5));
        self::assertSame([$s2], $selector->select([$s1, $s2], 'last', 5));
        self::assertSame([$s1, $s2], $selector->select([$s1, $s2], 'all', 5));
        self::assertSame([], $selector->select([$s1, $s2], '9', 5));
        self::assertSame([], $selector->select([$s1, $s2], '4', 5));
        self::assertSame([$s2], $selector->select([$s1, $s2], '2', 1));
    }
}
