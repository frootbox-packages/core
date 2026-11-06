<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Ai;

use DateTimeImmutable;
use Frootbox\Core\Action\Bookkeeping;
use Frootbox\Core\Action\ConflictException;
use Frootbox\Core\Action\CurrentContext;
use Frootbox\Core\Action\Journal;
use Frootbox\Core\Settings\Settings;
use Frootbox\Core\Support\Message as Text;

/**
 * The one way to the AI for every feature (decision 0294). Features call
 * it from their action, whose permission (ai.use) the Gate checks; the
 * Assistant checks the provider and the limit and notes what it cost.
 */
final readonly class Assistant
{
    /** No provider installed. */
    public const string NO_PROVIDER = 'provider';

    /** The provider lacks its setup, e.g. the API key. */
    public const string NOT_READY = 'setup';

    /** The monthly limit is reached. */
    public const string LIMIT = 'limit';

    public function __construct(
        private Providers $providers,
        private Settings $settings,
        private UsageLog $log,
        private Bookkeeping $bookkeeping,
        private Journal $journal,
        private CurrentContext $context,
    ) {}

    /**
     * Why the AI cannot answer now, null when it can.
     *
     * @return self::NO_PROVIDER|self::NOT_READY|self::LIMIT|null
     */
    public function unavailable(DateTimeImmutable $now = new DateTimeImmutable()): ?string
    {
        $provider = $this->providers->active();
        $limit = $this->settings->get(AiSettings::class)->limit();

        return match (true) {
            null === $provider => self::NO_PROVIDER,
            !$this->providers->client($provider)->ready() => self::NOT_READY,
            null !== $limit && $this->log->spent($now) >= $limit => self::LIMIT,
            default => null,
        };
    }

    /**
     * Sends a request to the model of the website and notes the costs
     * under the name of the feature, e.g. "field.rewrite".
     *
     * @throws ConflictException when no provider is ready or the limit is reached (409)
     * @throws AiException       when the provider cannot answer
     */
    public function ask(Request $request, string $feature): Response
    {
        $reason = $this->unavailable();

        if (null !== $reason) {
            throw new ConflictException(match ($reason) {
                self::NO_PROVIDER => new Text('No AI provider is installed.'),
                self::NOT_READY => new Text('The AI provider is not set up yet, e.g. its API key is missing.'),
                self::LIMIT => new Text('The monthly limit of the AI is reached. It goes on next month or with a higher limit.'),
            });
        }

        $provider = $this->providers->active();
        \assert(null !== $provider);
        $client = $this->providers->client($provider);
        $response = $client->send($request);
        $context = $this->context->get();
        $write = function () use ($client, $provider, $feature, $response, $context): void {
            foreach ($response->usage as $usage) {
                $this->log->record($context, $provider->name, $feature, $usage, self::prices($client, $usage->model)->cost($usage));
            }
        };

        // In an action the costs are written once it ended, so they stand when it fails.
        $this->journal->isRecording() ? $this->bookkeeping->afterwards($write) : $write();

        return $response;
    }

    /**
     * The prices of a model; one the provider does not list, e.g. after a
     * fallback, counts with those of the chosen model.
     */
    public static function prices(ProviderClient $client, string $model): Prices
    {
        foreach ($client->models() as $known) {
            if ($known->id === $model) {
                return $known->prices;
            }
        }

        return $client->model()->prices;
    }
}
