<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Hub;

use Frootbox\Core\Security\StandardWebhooks;
use PHPUnit\Framework\Assert;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The hub as "frootbox.de Connect v1" describes it (decision 0284), for
 * tests: checks every signature of the website with the key it was told.
 */
final class FakeHub
{
    public const string URL = 'https://account.hub.example.org';

    /** @var array{secret: string, public: string} */
    public array $keys;

    /** @var list<array{method: string, path: string, body: array<mixed>}> */
    public array $requests = [];

    /** Whether the hub cannot be reached. */
    public bool $down = false;

    /** The public key of the website, once it told the hub. */
    public ?string $siteKey = null;

    /** @var array{url: string, token: string}|null What the hub reads the status with */
    public ?array $status = null;

    public function __construct()
    {
        $this->keys = StandardWebhooks::keyPair();
    }

    public function client(): MockHttpClient
    {
        return new MockHttpClient($this->answer(...), self::URL);
    }

    /**
     * The last request to the hub with this method and path prefix.
     *
     * @return array<mixed>
     */
    public function last(string $method, string $path): array
    {
        foreach (array_reverse($this->requests) as $request) {
            if ($method === $request['method'] && str_starts_with($request['path'], $path)) {
                return $request['body'];
            }
        }

        Assert::fail(\sprintf('The hub got no %s %s.', $method, $path));
    }

    /**
     * Headers of a message from the hub to the website.
     *
     * @return array<string, string>
     */
    public function sign(string $body, ?string $secret = null, ?string $id = null): array
    {
        return StandardWebhooks::headers($secret ?? $this->keys['secret'], $id ?? 'msg_' . bin2hex(random_bytes(8)), $body);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function answer(string $method, string $url, array $options): MockResponse
    {
        if ($this->down) {
            return new MockResponse('', ['error' => 'Connection refused']);
        }

        $path = (string) parse_url($url, \PHP_URL_PATH);

        if ('GET' === $method && '/.well-known/frootbox/keys' === $path) {
            return self::json(['keys' => [['id' => 'k1', 'key' => $this->keys['public']]]]);
        }

        $raw = \is_string($options['body'] ?? null) ? $options['body'] : '';
        $body = '' === $raw ? [] : json_decode($raw, true, flags: \JSON_THROW_ON_ERROR);
        Assert::assertIsArray($body);
        $this->requests[] = ['method' => $method, 'path' => $path, 'body' => $body];

        // A new website tells its key, later the hub knows it.
        $key = \in_array($path, ['/connect/v1/requests'], true) || str_starts_with($path, '/connect/v1/codes/') ? $body['publicKey'] ?? null : $this->siteKey;
        $headers = [];
        $sent = \is_array($options['normalized_headers'] ?? null) ? $options['normalized_headers'] : [];

        foreach (['webhook-id', 'webhook-timestamp', 'webhook-signature'] as $name) {
            $line = \is_array($sent[$name] ?? null) && \is_string($sent[$name][0] ?? null) ? $sent[$name][0] : '';
            $headers[$name] = (string) preg_replace('~^[^:]+:\s*~', '', $line);
        }

        Assert::assertIsString($key);
        Assert::assertTrue(StandardWebhooks::verify($key, $headers, $raw), 'The website signs every request with its key.');
        Assert::assertStringStartsWith('v1a,', $headers['webhook-signature']);

        if ('/connect/v1/requests' === $path) {
            $this->siteKey = $key;

            return self::json(['id' => 'req_0123456789', 'url' => 'https://evil.example.org/phish']);
        }

        if (str_starts_with($path, '/connect/v1/codes/') || str_ends_with($path, '/complete')) {
            $this->siteKey = $key;
            $this->status = self::status($body['status'] ?? null);

            return self::json(['connection' => 'con_0123456789', 'organization' => 'Herr und Frau Pixel']);
        }

        if ('PATCH' === $method) {
            $this->status = self::status($body['status'] ?? null);
        }

        return new MockResponse('', ['http_code' => 204]);
    }

    /**
     * @return array{url: string, token: string}|null
     */
    private static function status(mixed $status): ?array
    {
        if (null === $status) {
            return null;
        }

        Assert::assertIsArray($status);
        Assert::assertIsString($status['url'] ?? null);
        Assert::assertIsString($status['token'] ?? null);

        return ['url' => $status['url'], 'token' => $status['token']];
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function json(array $data): MockResponse
    {
        return new MockResponse(json_encode($data, \JSON_THROW_ON_ERROR), ['response_headers' => ['Content-Type' => 'application/json']]);
    }
}
