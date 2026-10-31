<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Support;

use Frootbox\Core\Support\SortKey;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SortKey::class)]
final class SortKeyTest extends TestCase
{
    public function testFindsKeysBetweenOthers(): void
    {
        self::assertSame('i', SortKey::between(null, null));
        self::assertSame('r', SortKey::between('i', null));
        self::assertSame('9', SortKey::between(null, 'i'));
        self::assertSame('n', SortKey::between('i', 'r'));
        self::assertSame('ii', SortKey::between('i', 'j'));
        self::assertSame(['i', 'r', 'w', 'y', 'z', 'zi'], SortKey::sequence(6));
    }

    public function testAlwaysFindsRoomInBetween(): void
    {
        $keys = SortKey::sequence(3);

        for ($i = 0; $i < 500; ++$i) {
            $index = random_int(0, \count($keys));
            $key = SortKey::between($keys[$index - 1] ?? null, $keys[$index] ?? null);

            self::assertTrue(SortKey::isValid($key), $key);
            array_splice($keys, $index, 0, [$key]);
        }

        $sorted = $keys;
        sort($sorted, \SORT_STRING);

        self::assertSame($sorted, $keys);
        self::assertCount(503, array_unique($keys));
    }

    public function testRefusesWrongInput(): void
    {
        foreach ([['i0', null], ['I', null], ['r', 'i'], ['i', 'i']] as [$before, $after]) {
            try {
                SortKey::between($before, $after);
                self::fail(\sprintf('"%s" and "%s" were accepted.', $before, $after ?? 'null'));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
