<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Ai;

use DateTimeImmutable;
use Frootbox\Core\Action\AsQuery;
use Frootbox\Core\Action\Query;
use Frootbox\Core\Action\Schema\Description;
use Frootbox\Core\Action\Schema\Pattern;
use Frootbox\Core\Settings\Settings;

/**
 * @implements Query<UsageReport>
 */
#[AsQuery('ai.usage', 'Returns what the AI cost in a month in US dollars, with the monthly limit and what is left, by day, feature, person and model')]
final readonly class ShowAiUsage implements Query
{
    public function __construct(
        #[Description('The month as YYYY-MM, empty for the current one')]
        #[Pattern('^[0-9]{4}-(0[1-9]|1[0-2])$')]
        public ?string $month = null,
    ) {}

    public function handle(UsageLog $log, Settings $settings, Providers $providers): UsageReport
    {
        $month = null === $this->month ? new DateTimeImmutable() : new DateTimeImmutable($this->month . '-01');
        $days = $features = $people = $models = [];
        $total = $calls = 0;

        foreach ($log->rows($month) as $row) {
            $cost = self::int($row['cost'] ?? null);
            $total += $cost;
            ++$calls;

            $day = substr(self::text($row['created_at'] ?? null), 0, 10);
            $days[$day] ??= ['date' => $day, 'calls' => 0, 'cost' => 0];
            ++$days[$day]['calls'];
            $days[$day]['cost'] += $cost;

            $feature = self::text($row['feature'] ?? null);
            $features[$feature] ??= ['feature' => $feature, 'calls' => 0, 'cost' => 0];
            ++$features[$feature]['calls'];
            $features[$feature]['cost'] += $cost;

            $userId = null === ($row['user_id'] ?? null) ? null : self::int($row['user_id']);
            $name = self::text($row['actor'] ?? null);
            $person = null === $userId ? 'actor:' . $name : 'user:' . $userId;
            $people[$person] ??= ['userId' => $userId, 'name' => $name, 'calls' => 0, 'cost' => 0];
            ++$people[$person]['calls'];
            $people[$person]['cost'] += $cost;

            $model = self::text($row['model'] ?? null);
            $models[$model] ??= ['model' => $model, 'calls' => 0, 'inputTokens' => 0, 'outputTokens' => 0, 'cost' => 0];
            ++$models[$model]['calls'];
            $models[$model]['cost'] += $cost;
            $models[$model]['inputTokens'] += self::int($row['input_tokens'] ?? null) + self::int($row['cache_read_tokens'] ?? null) + self::int($row['cache_write_tokens'] ?? null);
            $models[$model]['outputTokens'] += self::int($row['output_tokens'] ?? null);
        }

        $byCost = static fn(array $a, array $b): int => $b['cost'] <=> $a['cost'];
        uasort($features, $byCost);
        uasort($people, $byCost);
        uasort($models, $byCost);
        $limit = $settings->get(AiSettings::class)->limit();
        $provider = $providers->active();

        return new UsageReport(
            $month->format('Y-m'),
            $provider?->title,
            null === $provider ? null : $providers->client($provider)->model()->title,
            null === $limit ? null : self::dollars($limit),
            self::dollars($total),
            null === $limit ? null : self::dollars(max(0, $limit - $total)),
            $calls,
            array_values(array_map(static fn(array $row): array => ['date' => $row['date'], 'calls' => $row['calls'], 'cost' => self::dollars($row['cost'])], $days)),
            array_values(array_map(static fn(array $row): array => ['feature' => $row['feature'], 'calls' => $row['calls'], 'cost' => self::dollars($row['cost'])], $features)),
            array_values(array_map(static fn(array $row): array => ['userId' => $row['userId'], 'name' => $row['name'], 'calls' => $row['calls'], 'cost' => self::dollars($row['cost'])], $people)),
            array_values(array_map(static fn(array $row): array => ['model' => $row['model'], 'calls' => $row['calls'], 'inputTokens' => $row['inputTokens'], 'outputTokens' => $row['outputTokens'], 'cost' => self::dollars($row['cost'])], $models)),
        );
    }

    public static function dollars(int $millionths): float
    {
        return round($millionths / 1_000_000, 6);
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function text(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }
}
