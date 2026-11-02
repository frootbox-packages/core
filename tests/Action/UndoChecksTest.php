<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Action;

use DI\Container;
use Frootbox\Core\Action\Undo\UndoChecks;
use Frootbox\Core\Action\Undo\UndoConflict;
use Frootbox\Core\Extension\Registry;
use Frootbox\Core\Tests\Fixtures\Undo\AlwaysInTheWay;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(UndoChecks::class)]
#[CoversClass(UndoConflict::class)]
#[CoversClass(Registry::class)]
final class UndoChecksTest extends TestCase
{
    public function testCollectsTheConflictsOfAllChecks(): void
    {
        $registry = new Registry();
        $registry->undoCheck(AlwaysInTheWay::class);
        $registry->undoCheck(AlwaysInTheWay::class);

        self::assertSame([AlwaysInTheWay::class], $registry->undoChecks(), 'Registered once.');

        $conflicts = new UndoChecks($registry->undoChecks(), new Container())->conflicts([]);

        self::assertCount(1, $conflicts);
        self::assertSame('Always in the way.', $conflicts[0]->message);
        self::assertSame(['things' => [1]], $conflicts[0]->rows);
    }
}
