<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Ai;

use DateTimeImmutable;
use Frootbox\Core\Config\Config;
use Frootbox\Core\Config\ConfigException;
use Frootbox\Core\Hub\HubClient;
use Frootbox\Core\Hub\HubConnections;
use Frootbox\Core\Hub\HubException;
use Frootbox\Core\ProjectPaths;

/**
 * The prices of the AI (decision 0297): "ai.prices" in config/app.yaml
 * first, then the list of the hub, then the provider module.
 *
 * A connected website asks the hub for the list of its provider only
 * when it used the AI since the last list and that list is older than a
 * day. The hub signs its answer. The list lives in var/hub/ and may get
 * lost with var/; the module counts until the next one.
 */
final readonly class PriceList
{
    /** How long a list counts as fresh. */
    public const int FRESH = 86_400;

    /** How long to wait after an attempt that failed. */
    public const int RETRY = 3_600;

    public const string PATH = '/connect/v1/connections/%s/prices';

    public function __construct(
        private ProjectPaths $paths,
        private Config $config,
        private Providers $providers,
        private HubConnections $connections,
        private HubClient $client,
        private UsageLog $log,
    ) {}

    /**
     * The prices of a model of a provider; one nobody knows, e.g. after a
     * fallback, counts with those of the chosen model.
     */
    public function of(string $provider, ProviderClient $client, string $model): ModelPrices
    {
        $own = $this->configured($model);

        if (null !== $own) {
            return new ModelPrices($own, ModelPrices::CONFIG);
        }

        $list = $this->stored();

        if (null !== $list && $list['provider'] === $provider && isset($list['models'][$model])) {
            return new ModelPrices($list['models'][$model], ModelPrices::HUB, $list['fetchedAt']);
        }

        foreach ($client->models() as $known) {
            if ($known->id === $model) {
                return new ModelPrices($known->prices, ModelPrices::MODULE);
            }
        }

        $chosen = $client->model();

        return $chosen->id === $model ? new ModelPrices($chosen->prices, ModelPrices::MODULE) : $this->of($provider, $client, $chosen->id);
    }

    /**
     * Whether the website should ask the hub now: connected, with a
     * provider, the AI used since the last list, which is older than a
     * day, and no attempt within the last hour.
     */
    public function due(DateTimeImmutable $now = new DateTimeImmutable()): bool
    {
        $connection = $this->connections->current();
        $provider = $this->providers->active();

        if (null === $connection || !$connection->connected() || [] === $connection->hubKeys || null === $provider) {
            return false;
        }

        $attempt = $this->time($this->attemptFile());

        if (null !== $attempt && $attempt > $now->modify('-' . self::RETRY . ' seconds')) {
            return false;
        }

        $list = $this->stored();
        $list = null !== $list && $list['provider'] === $provider->name ? $list : null;

        if (null !== $list && $list['fetchedAt'] > $now->modify('-' . self::FRESH . ' seconds')) {
            return false;
        }

        $used = $this->log->lastUse();

        return null !== $used && $used >= ($list['fetchedAt'] ?? $now->modify('-' . self::FRESH . ' seconds'));
    }

    /**
     * Asks the hub for the list of the active provider and keeps it.
     *
     * @return int The models in the list
     *
     * @throws HubException when the hub cannot be reached or its answer is not signed
     */
    public function refresh(DateTimeImmutable $now = new DateTimeImmutable()): int
    {
        $connection = $this->connections->current();
        $provider = $this->providers->active();

        if (null === $connection || null === $connection->connectionId || null === $provider) {
            return 0;
        }

        $this->touch($this->attemptFile());
        $answer = $this->client->fetchSigned($connection, \sprintf(self::PATH, rawurlencode($connection->connectionId)), ['provider' => $provider->name]);

        if (($answer['provider'] ?? null) !== $provider->name || !\is_array($answer['models'] ?? null)) {
            throw HubException::badAnswer('prices');
        }

        $models = [];

        foreach ($answer['models'] as $entry) {
            $id = \is_array($entry) ? ($entry['id'] ?? null) : null;
            $prices = \is_array($entry) ? self::prices($entry) : null;

            if (\is_string($id) && 1 === preg_match('~^[A-Za-z0-9._:-]{1,100}$~', $id) && null !== $prices) {
                $models[$id] = ['input' => $prices->input, 'output' => $prices->output, 'cacheRead' => $prices->cacheRead, 'cacheWrite' => $prices->cacheWrite];
            }
        }

        $this->write([
            'provider' => $provider->name,
            'fetchedAt' => $now->format(\DATE_ATOM),
            'updatedAt' => \is_string($answer['updatedAt'] ?? null) ? $answer['updatedAt'] : null,
            'models' => $models,
        ]);

        return \count($models);
    }

    /**
     * The list of the hub as the website keeps it.
     *
     * @return array{provider: string, fetchedAt: DateTimeImmutable, models: array<string, Prices>}|null
     */
    public function stored(): ?array
    {
        $file = $this->file();
        $raw = is_file($file) ? @file_get_contents($file) : false;
        $data = false === $raw ? null : json_decode($raw, true);

        if (!\is_array($data) || !\is_string($data['provider'] ?? null) || !\is_string($data['fetchedAt'] ?? null) || !\is_array($data['models'] ?? null)) {
            return null;
        }

        $fetchedAt = DateTimeImmutable::createFromFormat(\DATE_ATOM, $data['fetchedAt']);
        $models = [];

        foreach ($data['models'] as $id => $entry) {
            $prices = \is_array($entry) ? self::prices($entry) : null;

            if (\is_string($id) && null !== $prices) {
                $models[$id] = $prices;
            }
        }

        return false === $fetchedAt ? null : ['provider' => $data['provider'], 'fetchedAt' => $fetchedAt, 'models' => $models];
    }

    /**
     * Own prices of a model from "ai.prices.<model>" in config/app.yaml.
     * Without prices for the cache, reading and writing it costs as much
     * as the input.
     */
    private function configured(string $model): ?Prices
    {
        $all = $this->config->get('ai.prices', []);
        $entry = \is_array($all) ? ($all[$model] ?? null) : null;

        if (null === $entry) {
            return null;
        }

        $input = \is_array($entry) ? ($entry['input'] ?? null) : null;
        $prices = \is_array($entry) ? self::prices([...$entry, 'cacheRead' => $entry['cacheRead'] ?? $input, 'cacheWrite' => $entry['cacheWrite'] ?? $input]) : null;

        return $prices ?? throw new ConfigException(\sprintf('"ai.prices.%s" in config/app.yaml needs input and output in US dollars per million tokens, each a number of at least 0.', $model));
    }

    /**
     * @param array<mixed> $entry
     */
    private static function prices(array $entry): ?Prices
    {
        $values = [];

        foreach (['input', 'output', 'cacheRead', 'cacheWrite'] as $name) {
            $value = $entry[$name] ?? ('input' === $name || 'output' === $name ? null : 0);

            if (!\is_int($value) && !\is_float($value) || $value < 0 || !is_finite((float) $value)) {
                return null;
            }

            $values[] = (float) $value;
        }

        return new Prices(...$values);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function write(array $data): void
    {
        $file = $this->file();
        $directory = \dirname($file);

        if (!is_dir($directory) && !@mkdir($directory, 0o775, true) && !is_dir($directory)) {
            return;
        }

        $temporary = $file . '.' . bin2hex(random_bytes(4));

        if (false !== @file_put_contents($temporary, json_encode($data, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES))) {
            @rename($temporary, $file);
        }
    }

    private function touch(string $file): void
    {
        $directory = \dirname($file);

        if (is_dir($directory) || @mkdir($directory, 0o775, true) || is_dir($directory)) {
            @touch($file);
        }
    }

    private function time(string $file): ?DateTimeImmutable
    {
        clearstatcache(true, $file);
        $time = is_file($file) ? filemtime($file) : false;

        return false === $time ? null : new DateTimeImmutable()->setTimestamp($time);
    }

    private function file(): string
    {
        return $this->paths->var('hub/ai-prices.json');
    }

    private function attemptFile(): string
    {
        return $this->paths->var('hub/ai-prices.attempt');
    }
}
