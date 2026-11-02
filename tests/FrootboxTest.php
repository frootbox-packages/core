<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests;

use Frootbox\Core\Frootbox;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Frootbox::class)]
final class FrootboxTest extends TestCase
{
    public function testVersionIsSemantic(): void
    {
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+(-[0-9A-Za-z.]+)?$/', Frootbox::VERSION);
    }

    public function testRunningPhpMeetsMinimumVersion(): void
    {
        self::assertTrue(version_compare(PHP_VERSION, Frootbox::MINIMUM_PHP_VERSION, '>='));
    }
}
