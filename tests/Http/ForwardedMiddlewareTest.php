<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Http;

use Frootbox\Core\Config\ConfigException;
use Frootbox\Core\Http\ClientAddress;
use Frootbox\Core\Http\ForwardedMiddleware;
use Frootbox\Core\Http\TrustedProxies;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Headers of a proxy only count when it is trusted (decision 0343).
 */
#[CoversClass(ForwardedMiddleware::class)]
#[CoversClass(TrustedProxies::class)]
#[CoversClass(ClientAddress::class)]
final class ForwardedMiddlewareTest extends TestCase
{
    public function testMatchesAddressesAndNetworks(): void
    {
        $proxies = new TrustedProxies(['10.0.0.0/8', '192.168.1.7', '2001:db8::/32', '172.16.0.0/12']);

        self::assertTrue($proxies->trusts('10.1.2.3'));
        self::assertTrue($proxies->trusts('192.168.1.7'));
        self::assertFalse($proxies->trusts('192.168.1.8'));
        self::assertTrue($proxies->trusts('2001:db8:1::5'));
        self::assertFalse($proxies->trusts('2001:db9::1'));
        self::assertTrue($proxies->trusts('172.31.255.255'));
        self::assertFalse($proxies->trusts('172.32.0.1'));
        self::assertFalse($proxies->trusts('not an address'));
        self::assertFalse(new TrustedProxies()->trusts('10.1.2.3'));
    }

    public function testRefusesBrokenEntries(): void
    {
        $this->expectException(ConfigException::class);
        new TrustedProxies(['10.0.0.0/33']);
    }

    public function testIgnoresTheHeadersWithoutATrustedProxy(): void
    {
        $request = self::request('203.0.113.9', ['X-Forwarded-For' => '198.51.100.1', 'X-Forwarded-Proto' => 'https']);

        foreach ([new TrustedProxies(), new TrustedProxies(['10.0.0.0/8'])] as $proxies) {
            $applied = ForwardedMiddleware::apply($request, $proxies);
            self::assertSame('203.0.113.9', ClientAddress::of($applied));
            self::assertSame('http', $applied->getUri()->getScheme());
        }
    }

    public function testTakesTheClientAndTheSchemeFromATrustedProxy(): void
    {
        $request = self::request('10.0.0.2', [
            // A forged first entry from the client, then the client, then another proxy of ours.
            'X-Forwarded-For' => '1.2.3.4, 198.51.100.1, 10.0.0.5',
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Port' => '443',
        ]);
        $applied = ForwardedMiddleware::apply($request, new TrustedProxies(['10.0.0.0/8']));

        self::assertSame('198.51.100.1', ClientAddress::of($applied));
        self::assertSame('https', $applied->getUri()->getScheme());
        self::assertNull($applied->getUri()->getPort());
        self::assertSame('example.com', $applied->getUri()->getHost());
    }

    public function testStopsAtABrokenEntry(): void
    {
        $request = self::request('10.0.0.2', ['X-Forwarded-For' => '198.51.100.1, garbage, 10.0.0.5']);

        self::assertSame('10.0.0.5', ClientAddress::of(ForwardedMiddleware::apply($request, new TrustedProxies(['10.0.0.0/8']))));
    }

    public function testReadsPortsAndBrackets(): void
    {
        $request = self::request('10.0.0.2', ['X-Forwarded-For' => '[2001:db8::1]:51000', 'X-Forwarded-Proto' => 'HTTPS, http', 'X-Forwarded-Port' => '8443']);
        $applied = ForwardedMiddleware::apply($request, new TrustedProxies(['10.0.0.2']));

        self::assertSame('2001:db8::1', ClientAddress::of($applied));
        self::assertSame('https', $applied->getUri()->getScheme());
        self::assertSame(8443, $applied->getUri()->getPort());
    }

    public function testKeepsTheProxyWithoutHeaders(): void
    {
        $applied = ForwardedMiddleware::apply(self::request('10.0.0.2', []), new TrustedProxies(['10.0.0.0/8']));

        self::assertSame('10.0.0.2', ClientAddress::of($applied));
        self::assertSame('http', $applied->getUri()->getScheme());
    }

    /**
     * @param array<string, string> $headers
     */
    private static function request(string $remote, array $headers): ServerRequestInterface
    {
        return new ServerRequest('GET', 'http://example.com/', $headers, null, '1.1', ['REMOTE_ADDR' => $remote]);
    }
}
