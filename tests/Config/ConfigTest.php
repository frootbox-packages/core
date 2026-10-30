<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Config;

use Frootbox\Core\Config\Config;
use Frootbox\Core\Config\ConfigException;
use Frootbox\Core\Config\Environment;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Config::class)]
#[CoversClass(Environment::class)]
final class ConfigTest extends TestCase
{
    public function testReadsNestedValuesByPath(): void
    {
        $config = new Config(Environment::Production, ['app' => ['name' => 'Site', 'cache' => true]]);

        self::assertSame('Site', $config->string('app.name'));
        self::assertTrue($config->bool('app.cache'));
        self::assertSame('fallback', $config->get('app.missing', 'fallback'));
        self::assertSame('fallback', $config->string('app.name.deeper', 'fallback'));
    }

    public function testRejectsWrongType(): void
    {
        $config = new Config(Environment::Production, ['app' => ['name' => 42]]);

        $this->expectException(ConfigException::class);

        $config->string('app.name');
    }

    public function testRejectsMissingValueWithoutDefault(): void
    {
        $config = new Config(Environment::Production);

        $this->expectException(ConfigException::class);

        $config->bool('app.cache');
    }

    public function testKnowsDevelopment(): void
    {
        self::assertTrue(new Config(Environment::Development)->environment->isDevelopment());
        self::assertFalse(new Config(Environment::Production)->environment->isDevelopment());
    }

    public function testReadsEnvironmentVariables(): void
    {
        $config = new Config(Environment::Production, [], ['APP_ENV' => 'prod']);

        self::assertSame('prod', $config->env('APP_ENV'));
        self::assertNull($config->env('MISSING'));
    }
}
