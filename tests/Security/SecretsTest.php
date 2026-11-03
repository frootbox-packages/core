<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Security;

use Frootbox\Core\Config\Config;
use Frootbox\Core\Config\Environment;
use Frootbox\Core\Security\Secrets;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Secrets::class)]
final class SecretsTest extends TestCase
{
    public function testSignsATextForAPurpose(): void
    {
        $secrets = self::secrets('test-secret-0123456789abcdef');
        $signature = $secrets->sign('forms', '01JD|1793664000');

        self::assertNotNull($signature);
        self::assertTrue($secrets->verify('forms', '01JD|1793664000', $signature));
        self::assertFalse($secrets->verify('forms', '01JD|1793664001', $signature), 'Another text fails.');
        self::assertFalse($secrets->verify('other', '01JD|1793664000', $signature), 'Another purpose fails.');
        self::assertFalse(self::secrets('another-secret-0123456789')->verify('forms', '01JD|1793664000', $signature), 'Another APP_SECRET fails.');
    }

    public function testSignsNothingWithoutASecret(): void
    {
        $secrets = self::secrets('short');

        self::assertNull($secrets->sign('forms', 'text'));
        self::assertFalse($secrets->verify('forms', 'text', ''));
    }

    private static function secrets(string $secret): Secrets
    {
        return new Secrets(new Config(Environment::Production, [], ['APP_SECRET' => $secret]));
    }
}
