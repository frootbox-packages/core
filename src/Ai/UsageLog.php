<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Ai;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Frootbox\Core\Action\Context;
use Frootbox\Database\Ulid;
use Frootbox\Database\Values;

/**
 * What the AI used and cost (decision 0294). Bookkeeping, not an action:
 * the costs stand when the action fails or is undone.
 */
final readonly class UsageLog
{
    public const string TABLE = 'ai_usage';

    public function __construct(private Connection $connection) {}

    public function record(Context $context, string $provider, string $feature, Usage $usage, int $cost, DateTimeImmutable $now = new DateTimeImmutable()): void
    {
        $this->connection->insert(self::TABLE, [
            'id' => Ulid::generate(),
            'created_at' => $now->format(Values::DATE_FORMAT),
            'user_id' => $context->actor->id,
            'actor' => $context->actor->name,
            'feature' => $feature,
            'channel' => $context->channel->value,
            'provider' => $provider,
            'model' => $usage->model,
            'input_tokens' => $usage->input,
            'output_tokens' => $usage->output,
            'cache_read_tokens' => $usage->cacheRead,
            'cache_write_tokens' => $usage->cacheWrite,
            'cost' => $cost,
        ]);
    }

    /**
     * The costs from the start of a month to the start of the next one, in
     * millionths of a dollar.
     */
    public function spent(DateTimeImmutable $month): int
    {
        [$from, $to] = self::bounds($month);

        $sum = $this->connection->fetchOne(
            'SELECT SUM(cost) FROM ' . self::TABLE . ' WHERE created_at >= ? AND created_at < ?',
            [$from->format(Values::DATE_FORMAT), $to->format(Values::DATE_FORMAT)],
        );

        return is_numeric($sum) ? (int) $sum : 0;
    }

    /**
     * The rows of a month, oldest first.
     *
     * @return list<array<string, mixed>>
     */
    public function rows(DateTimeImmutable $month): array
    {
        [$from, $to] = self::bounds($month);

        return $this->connection->fetchAllAssociative(
            'SELECT * FROM ' . self::TABLE . ' WHERE created_at >= ? AND created_at < ? ORDER BY created_at, id',
            [$from->format(Values::DATE_FORMAT), $to->format(Values::DATE_FORMAT)],
        );
    }

    /**
     * The start of the calendar month in the time zone of the website and
     * of the next one.
     *
     * @return array{DateTimeImmutable, DateTimeImmutable}
     */
    public static function bounds(DateTimeImmutable $month): array
    {
        $from = $month->modify('first day of this month')->setTime(0, 0);

        return [$from, $from->modify('+1 month')];
    }
}
