<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Hub;

use DateTimeImmutable;
use Frootbox\Core\Auth\AccessTokens;
use Frootbox\Core\Config\Config;
use Frootbox\Core\Frootbox;
use Frootbox\Core\Security\Secrets;
use Frootbox\Core\Security\StandardWebhooks;

/**
 * What the actions of the hub share (decision 0284): the identity of the
 * website, what it tells the hub about itself and why it cannot connect.
 */
final readonly class Hub
{
    /** How long a request waits for the confirmation on the hub. */
    public const int REQUEST_MINUTES = 15;

    /** Ids of the hub, e.g. of a request or a connection. */
    public const string ID = '[A-Za-z0-9_-]{8,100}';

    public function __construct(
        private HubConnections $connections,
        private HubClient $client,
        private Config $config,
        private Secrets $secrets,
        private AccessTokens $tokens,
    ) {}

    public function current(): ?HubConnection
    {
        return $this->connections->current();
    }

    public function connected(): HubConnection
    {
        $connection = $this->current();

        return null !== $connection && $connection->connected() ? $connection : throw HubException::notConnected();
    }

    /**
     * The address of the website from app.url, without a trailing slash.
     */
    public function siteUrl(): ?string
    {
        $url = $this->config->get('app.url');

        return \is_string($url) && 1 === preg_match('#^https?://[^/?\#]+(/[^?\#]*)?$#', $url) ? rtrim($url, '/') : null;
    }

    /**
     * Why the website cannot connect, null if it can: "secret" without
     * APP_SECRET, "address" without app.url, "hub-url" for a broken
     * hub.url, "local" for a local website and a hub on the internet.
     */
    public function hint(): ?string
    {
        try {
            $this->check();
        } catch (HubException $error) {
            return $error->reason;
        }

        return null;
    }

    /**
     * @return array{site: string, hub: string}
     *
     * @throws HubException if the website cannot connect
     */
    public function check(): array
    {
        if (!$this->secrets->available()) {
            throw HubException::noSecret();
        }

        $site = $this->siteUrl() ?? throw HubException::noAddress();
        $hub = $this->client->url();

        // The hub refuses them anyway; a local hub, for development, takes them.
        if (HubAddress::isLocal($site) && !HubAddress::isLocal($hub)) {
            throw HubException::local($site);
        }

        return ['site' => $site, 'hub' => $hub];
    }

    /**
     * The row of the website for the hub, with a key pair made on first
     * use and the keys of the hub read fresh.
     */
    public function identity(string $hubUrl): HubConnection
    {
        $connection = $this->current();

        if (null !== $connection && $connection->connected()) {
            throw HubException::connected();
        }

        if (null === $connection) {
            $pair = StandardWebhooks::keyPair();
            $connection = $this->connections->insert([
                'hubUrl' => $hubUrl,
                'publicKey' => $pair['public'],
                'secretKey' => $this->client->encrypt($pair['secret']),
                'hubKeys' => [],
                'createdAt' => new DateTimeImmutable(),
            ]);
        } else {
            // A key that cannot be read any more is no identity.
            $this->client->secretKey($connection);
        }

        return $this->connections->update($connection->id, ['hubUrl' => $hubUrl, 'hubKeys' => $this->client->keys()]);
    }

    /**
     * What the website tells the hub about itself when it connects.
     *
     * @return array<string, mixed>
     */
    public function describe(HubConnection $connection, string $siteUrl, bool $status): array
    {
        return [
            'url' => $siteUrl,
            'name' => $this->config->string('app.name', (string) parse_url($siteUrl, \PHP_URL_HOST)),
            'system' => ['name' => 'frootbox', 'version' => Frootbox::version()],
            'publicKey' => $connection->publicKey,
            'switches' => self::switches($status),
        ];
    }

    /**
     * The switches of decision 0283: "status" and "login" (decision 0322)
     * work, "packages" comes with F1.
     *
     * @return array{status: bool, packages: bool, login: bool}
     */
    public static function switches(bool $status, bool $login = false): array
    {
        return ['status' => $status, 'packages' => false, 'login' => $login];
    }

    /**
     * How people know the hub: "hub.frootbox.de" reads as "frootbox.de"
     * (decision 0288).
     */
    public static function name(string $hubUrl): string
    {
        $host = parse_url($hubUrl, \PHP_URL_HOST);

        return \is_string($host) ? (string) preg_replace('~^hub\.~', '', $host) : $hubUrl;
    }

    public function details(): HubDetails
    {
        $connection = $this->current();
        $connected = null !== $connection && $connection->connected();
        $pending = !$connected && null !== $connection && $connection->pending();

        try {
            $hubUrl = $this->client->url();
        } catch (HubException) {
            $hubUrl = null;
        }

        $lastPoll = null;

        if ($connected && null !== $connection->userId) {
            foreach ($this->tokens->forUser($connection->userId) as $token) {
                if (null !== $token->lastUsedAt && (null === $lastPoll || $token->lastUsedAt > $lastPoll)) {
                    $lastPoll = $token->lastUsedAt;
                }
            }
        }

        $siteUrl = $this->siteUrl();

        return new HubDetails(
            state: $connected ? 'connected' : ($pending ? 'pending' : 'none'),
            hubUrl: $connected ? $connection->hubUrl : $hubUrl,
            siteUrl: $siteUrl,
            hint: $connected ? null : $this->hint(),
            requestId: $pending ? $connection->requestId : null,
            requestUrl: $pending ? $connection->hubUrl . '/connect/' . $connection->requestId : null,
            requestExpiresAt: $pending ? $connection->requestExpiresAt?->format(\DATE_ATOM) : null,
            connectionId: $connected ? $connection->connectionId : null,
            organization: $connected ? $connection->organization : null,
            connectedAt: $connected ? $connection->connectedAt?->format(\DATE_ATOM) : null,
            switches: self::switches($connected && $connection->status, $connected && $connection->login),
            publicKey: $connection?->publicKey,
            statusUrl: $connected && $connection->status && null !== $siteUrl ? $siteUrl . '/api/v1/status.show' : null,
            lastPoll: $lastPoll?->format(\DATE_ATOM),
            loginMaxRole: $connected && $connection->login ? $connection->loginMaxRole : null,
        );
    }
}
