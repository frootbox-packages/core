<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Auth;

use Frootbox\Core\Auth\PermissionRules;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(PermissionRules::class)]
final class PermissionRulesTest extends TestCase
{
    public function testAStarAllowsEverything(): void
    {
        $rules = new PermissionRules(['*']);

        self::assertTrue($rules->allows('page.create'));
        self::assertTrue($rules->allows('news.category.delete'));
    }

    public function testAPrefixAllowsEverythingBelowIt(): void
    {
        $rules = new PermissionRules(['page.*', 'media.upload']);

        self::assertTrue($rules->allows('page.create'));
        self::assertTrue($rules->allows('page.block.move'));
        self::assertTrue($rules->allows('media.upload'));
        self::assertFalse($rules->allows('media.delete'));
        self::assertFalse($rules->allows('pages.create'));
        self::assertFalse($rules->allows('page'));
    }

    public function testExceptionsWinRegardlessOfOrder(): void
    {
        $rules = new PermissionRules(['-page.delete', 'page.*', '-news.*', '*']);

        self::assertTrue($rules->allows('page.create'));
        self::assertFalse($rules->allows('page.delete'));
        self::assertFalse($rules->allows('news.create'));
        self::assertTrue($rules->allows('media.upload'));
    }

    public function testNoRulesAllowNothing(): void
    {
        self::assertFalse(new PermissionRules([])->allows('page.create'));
    }

    #[DataProvider('invalidRules')]
    public function testRejectsInvalidRules(string $rule): void
    {
        self::assertFalse(PermissionRules::isValid($rule));

        $this->expectException(InvalidArgumentException::class);
        new PermissionRules([$rule]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidRules(): iterable
    {
        yield 'empty' => [''];
        yield 'upper case' => ['Page.create'];
        yield 'star in the middle' => ['page.*.create'];
        yield 'two stars' => ['**'];
        yield 'double minus' => ['--page.create'];
        yield 'trailing dot' => ['page.'];
    }
}
