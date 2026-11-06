<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Hub;

use Frootbox\Core\Config\Config;
use Frootbox\Core\Security\Secrets;
use Frootbox\Core\Security\StandardWebhooks;
use Frootbox\Database\Ulid;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Talks to the hub, "frootbox.de Connect v1" (decision 0284): every
 * request is JSON, signed with the key of the website after Standard
 * Webhooks "v1a". Only the keys of the hub are read unsigned, over TLS.
 */
final readonly class HubClient
{
    /** The hub unless "hub.url" in config/app.yaml says otherwise. */
    public const string DEFAULT_URL = 'https://hub.frootbox.de';

    /** The purpose of the secret key for Secrets. */
    public const string PURPOSE = 'hub.secret-key';

    private HttpClientInterface $client;

    public function __construct(
        private Config $config,
        private Secrets $secrets,
        ?HttpClientInterface $client = null,
    ) {
        $this->client = $client ?? HttpClient::create(['timeout' => 15, 'max_redirects' => 0]);
    }

    /**
     * The address of the hub without a trailing slash. Only a local hub
     * may go without HTTPS.
     *
     * @throws HubException for an address that is no hub
     */
    public function url(): string
    {
        $url = rtrim($this->config->string('hub.url', self::DEFAULT_URL), '/');
        $scheme = parse_url($url, \PHP_URL_SCHEME);

        if (1 !== preg_match('#^https?://[^/?\#]+$#', $url) || ('https' !== $scheme && !HubAddress::isLocal($url))) {
            throw HubException::badHubUrl($url);
        }

        return $url;
    }

    /**
     * The public keys of the hub, from {hub}/.well-known/frootbox/keys.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        $data = $this->request('GET', $this->url() . '/.well-known/frootbox/keys');
        $keys = [];

        foreach (\is_array($data['keys'] ?? null) ? $data['keys'] : [] as $entry) {
            $key = \is_array($entry) ? ($entry['key'] ?? null) : $entry;

            if (\is_string($key) && 1 === preg_match('~^whpk_[A-Za-z0-9+/]{43}=$~', $key)) {
                $keys[] = $key;
            }
        }

        return [] === $keys ? throw HubException::badAnswer('keys') : array_values(array_unique($keys));
    }

    /**
     * A signed request to the hub, e.g. POST /connect/v1/requests.
     *
     * @param array<string, mixed> $body
     *
     * @return array<mixed> The answer, empty for 204
     */
    public function send(HubConnection $connection, string $method, string $path, array $body = []): array
    {
        $secret = $this->secretKey($connection);
        $json = json_encode((object) $body, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        $headers = StandardWebhooks::headers($secret, 'msg_' . Ulid::generate(), $json);

        return $this->request($method, $connection->hubUrl . $path, ['Content-Type' => 'application/json', ...$headers], $json);
    }

    /**
     * Like send(), for an answer the hub signs with its key, e.g. the
     * prices of the AI (decision 0297): one without a valid signature of
     * a key from the connection is no answer.
     *
     * @param array<string, mixed> $body
     *
     * @return array<mixed>
     */
    public function fetchSigned(HubConnection $connection, string $path, array $body = []): array
    {
        $secret = $this->secretKey($connection);
        $json = json_encode((object) $body, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        $headers = StandardWebhooks::headers($secret, 'msg_' . Ulid::generate(), $json);
        $answer = $this->exchange('POST', $connection->hubUrl . $path, ['Content-Type' => 'application/json', ...$headers], $json);
        $data = self::decode($answer);

        if ([] === $connection->hubKeys || !StandardWebhooks::verify($connection->hubKeys, $answer['headers'], $answer['content'])) {
            throw HubException::badAnswer('signature');
        }

        return $data;
    }

    /**
     * The secret key "whsk_…" of the website.
     */
    public function secretKey(HubConnection $connection): string
    {
        return $this->secrets->decrypt(self::PURPOSE, $connection->secretKey) ?? throw HubException::keyUnreadable();
    }

    public function encrypt(string $secretKey): string
    {
        return $this->secrets->encrypt(self::PURPOSE, $secretKey);
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array<mixed>
     */
    private function request(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        return self::decode($this->exchange($method, $url, $headers, $body));
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array{status: int, content: string, headers: array<string, string>}
     */
    private function exchange(string $method, string $url, array $headers, ?string $body): array
    {
        $options = ['headers' => ['Accept' => 'application/json', ...$headers], ...null === $body ? [] : ['body' => $body]];

        try {
            $response = $this->client->request($method, $url, $options);

            return [
                'status' => $response->getStatusCode(),
                'content' => $response->getContent(false),
                'headers' => array_map(static fn(array $values): string => implode(', ', $values), $response->getHeaders(false)),
            ];
        } catch (ExceptionInterface $error) {
            throw HubException::unreachable($url, $error);
        }
    }

    /**
     * @param array{status: int, content: string, headers: array<string, string>} $answer
     *
     * @return array<mixed>
     */
    private static function decode(array $answer): array
    {
        $status = $answer['status'];
        $data = '' === $answer['content'] ? [] : json_decode($answer['content'], true);

        if ($status >= 400) {
            $detail = \is_array($data) && \is_string($data['detail'] ?? null) ? $data['detail'] : (\is_array($data) && \is_string($data['title'] ?? null) ? $data['title'] : 'no reason given');

            throw HubException::refused($status, $detail);
        }

        if ($status >= 300 || !\is_array($data)) {
            throw HubException::badAnswer('JSON');
        }

        return $data;
    }
}
