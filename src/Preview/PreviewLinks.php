<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Preview;

use DateTimeImmutable;
use Frootbox\Database\Repository;
use Frootbox\Database\Where;

/**
 * @extends Repository<PreviewLink>
 */
final class PreviewLinks extends Repository
{
    public const string TABLE = 'preview_links';

    public function byToken(string $token): ?PreviewLink
    {
        return $this->fetchOne(['where' => ['token_hash' => PreviewTokens::hash($token)]]);
    }

    /**
     * Links that ended before then: revoked, or expired without being revoked.
     *
     * @return list<PreviewLink>
     */
    public function endedBefore(DateTimeImmutable $before): array
    {
        return $this->fetch(['where' => [Where::any(['revokedAt <' => $before], ['revokedAt' => null, 'expiresAt <' => $before])]])->all();
    }

    protected function table(): string
    {
        return self::TABLE;
    }

    protected function entity(): string
    {
        return PreviewLink::class;
    }
}
