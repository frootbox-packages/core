<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Config;

use Frootbox\Core\Config\Config;
use Frootbox\Core\Config\ConfigException;
use Frootbox\Core\Config\Environment;
use Frootbox\Core\Config\TimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TimeZone::class)]
final class TimeZoneTest extends TestCase
{
    private string $before;

    protected function setUp(): void
    {
        $this->before = date_default_timezone_get();
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->before);
    }

    public function testSetsTheTimeZoneOfTheWebsite(): void
    {
        date_default_timezone_set('UTC');

        TimeZone::apply(new Config(Environment::Production, ['app' => ['timezone' => 'Europe/Berlin']]));

        self::assertSame('Europe/Berlin', date_default_timezone_get());
    }

    public function testWithoutOnePhpKeepsItsOwn(): void
    {
        date_default_timezone_set('Asia/Tokyo');

        TimeZone::apply(new Config(Environment::Production, ['app' => ['name' => 'Site']]));

        self::assertSame('Asia/Tokyo', date_default_timezone_get());
    }

    public function testExplainsAnUnknownName(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('"app.timezone" in config/app.yaml must be a time zone like "Europe/Berlin", not "Berlin".');

        TimeZone::apply(new Config(Environment::Production, ['app' => ['timezone' => 'Berlin']]));
    }
}
