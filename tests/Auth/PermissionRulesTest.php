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

    public function testRulesForVendorsAndPackages(): void
    {
        $rules = new PermissionRules(['acme/*', '-acme/shop/order.delete', 'foo/blog/post.*', 'bar/news/article.publish']);

        self::assertTrue($rules->allows('acme/shop/order.create'));
        self::assertTrue($rules->allows('acme/guestbook/entry.sign'));
        self::assertFalse($rules->allows('acme/shop/order.delete'));
        self::assertTrue($rules->allows('foo/blog/post.create'));
        self::assertFalse($rules->allows('foo/blog/comment.create'));
        self::assertTrue($rules->allows('bar/news/article.publish'));
        self::assertFalse($rules->allows('bar/news/article.delete'));
        self::assertFalse($rules->allows('acme-other/shop/order.create'));
        self::assertFalse($rules->allows('page.create'));

        $package = new PermissionRules(['acme/shop/*']);
        self::assertTrue($package->allows('acme/shop/order.create'));
        self::assertFalse($package->allows('acme/shop-two/order.create'));
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
        yield 'vendor only' => ['acme/'];
        yield 'package only' => ['acme/shop/'];
        yield 'star inside a package' => ['acme/*/order.create'];
        yield 'leading slash' => ['/page.create'];
        yield 'upper case vendor' => ['Acme/*'];
    }
}
