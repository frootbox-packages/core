<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Auth;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Frootbox\Core\Action\Journal;
use Frootbox\Core\Config\Config;
use Frootbox\Core\User\Users;
use Frootbox\Database\DirectWriter;
use Frootbox\Database\EntityMapper;
use Frootbox\Database\Values;

/**
 * Sessions and API tokens of users.
 *
 * Tokens are bookkeeping like the action log: they are written directly,
 * not recorded for undoing. A token is "fbx_" plus 64 hex characters, the
 * table keeps only its SHA-256 hash.
 */
final readonly class AccessTokens
{
    public const string TABLE = 'access_tokens';

    private const string PREFIX = 'fbx_';

    /** Last use is written at most this often per token. */
    private const int TOUCH_SECONDS = 300;

    private DirectWriter $writer;

    public function __construct(
        private Connection $connection,
        private Journal $journal,
        private Config $config,
    ) {
        $this->writer = new DirectWriter($connection);
    }

    public function issue(int $userId, TokenType $type, ?string $name = null, ?DateTimeImmutable $expiresAt = null): IssuedToken
    {
        $token = self::PREFIX . bin2hex(random_bytes(32));
        $now = new DateTimeImmutable();

        $row = $this->journal->trusted(fn(): array => $this->writer->insert(self::TABLE, [
            'user_id' => $userId,
            'type' => $type,
            'name' => $name,
            'token_hash' => self::hash($token),
            'expires_at' => $expiresAt ?? (TokenType::Session === $type ? $this->sessionEnd($now) : null),
            'created_at' => $now,
        ]));

        return new IssuedToken($token, EntityMapper::hydrate(AccessToken::class, $row));
    }

    /**
     * Checks a token and returns its user, or null if it is unknown,
     * expired or the user is disabled. Sessions are extended while used.
     */
    public function authenticate(string $token): ?Authentication
    {
        if (!str_starts_with($token, self::PREFIX)) {
            return null;
        }

        $row = $this->connection->fetchAssociative(
            'SELECT t.*, u.name AS user_name, u.active AS user_active FROM ' . self::TABLE . ' t INNER JOIN ' . Users::TABLE . ' u ON u.id = t.user_id WHERE t.token_hash = ?',
            [self::hash($token)],
        );

        if (false === $row || !(bool) $row['user_active']) {
            return null;
        }

        $accessToken = EntityMapper::hydrate(AccessToken::class, $row);
        $now = new DateTimeImmutable();

        if (null !== $accessToken->expiresAt && $accessToken->expiresAt <= $now) {
            return null;
        }

        if (null === $accessToken->lastUsedAt || $now->getTimestamp() - $accessToken->lastUsedAt->getTimestamp() >= self::TOUCH_SECONDS) {
            $changes = ['last_used_at' => $now];

            if (TokenType::Session === $accessToken->type) {
                $changes['expires_at'] = $this->sessionEnd($now);
            }

            $accessToken = EntityMapper::hydrate(AccessToken::class, $this->journal->trusted(fn(): array => $this->writer->update(self::TABLE, $accessToken->id, $changes)));
        }

        $name = $row['user_name'];

        return new Authentication(Actor::user($accessToken->userId, \is_string($name) ? $name : ''), $accessToken);
    }

    public function find(int $id): ?AccessToken
    {
        $row = $this->writer->find(self::TABLE, $id);

        return null === $row ? null : EntityMapper::hydrate(AccessToken::class, $row);
    }

    /**
     * @return list<AccessToken>
     */
    public function forUser(int $userId): array
    {
        $rows = $this->connection->fetchAllAssociative('SELECT * FROM ' . self::TABLE . ' WHERE user_id = ? ORDER BY id', [$userId]);

        return array_map(static fn(array $row): AccessToken => EntityMapper::hydrate(AccessToken::class, $row), $rows);
    }

    public function revoke(int $id): bool
    {
        return $this->journal->trusted(fn(): bool => $this->writer->delete(self::TABLE, $id));
    }

    /**
     * Signs a user out everywhere, except for one token if given.
     */
    public function revokeAll(int $userId, ?int $except = null): int
    {
        return $this->journal->trusted(function () use ($userId, $except): int {
            $sql = 'DELETE FROM ' . self::TABLE . ' WHERE user_id = ?';
            $params = [$userId];

            if (null !== $except) {
                $sql .= ' AND id <> ?';
                $params[] = $except;
            }

            return (int) $this->connection->executeStatement($sql, $params);
        });
    }

    /**
     * Deletes expired tokens.
     */
    public function prune(): int
    {
        return $this->journal->trusted(fn(): int => (int) $this->connection->executeStatement(
            'DELETE FROM ' . self::TABLE . ' WHERE expires_at IS NOT NULL AND expires_at <= ?',
            [new DateTimeImmutable()->format(Values::DATE_FORMAT)],
        ));
    }

    private function sessionEnd(DateTimeImmutable $now): DateTimeImmutable
    {
        return $now->modify(\sprintf('+%d days', $this->config->int('auth.session_days', 14)));
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
