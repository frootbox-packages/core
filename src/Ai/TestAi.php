<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Ai;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Action\InvalidInputException;
use Frootbox\Core\Config\Config;
use Frootbox\Core\I18n\Translator;

/**
 * A very small request to the provider, to see that key and model work
 * (decision 0294). It costs a fraction of a cent and counts like any other.
 *
 * @implements Action<TestResult>
 */
#[AsAction('ai.test', 'Sends a very small request to the AI provider to check its key and model, and returns the answer, the tokens, the cost in US dollars and the time it took', permission: 'settings.update', undoable: false)]
final readonly class TestAi implements Action
{
    public const string FEATURE = 'test';

    public function handle(Assistant $assistant, Providers $providers, PriceList $prices, Config $config, Translator $translator): TestResult
    {
        $started = hrtime(true);

        try {
            $response = $assistant->ask(new Request(
                'You check that a website reaches its AI. Answer with one short, friendly sentence and nothing else.',
                [Message::user($translator->get('Greet the website {site} in the language of this sentence.', ['site' => $config->string('app.name', 'Frootbox')]))],
                maxTokens: 1024,
                effort: Effort::Low,
                cache: false,
            ), self::FEATURE);
        } catch (AiException $error) {
            throw new InvalidInputException('ai.test', ['' => $error->text]);
        }

        $provider = $providers->active();
        \assert(null !== $provider);
        $client = $providers->client($provider);
        $cost = array_sum(array_map(static fn(Usage $usage): int => $prices->of($provider->name, $client, $usage->model)->prices->cost($usage), $response->usage));

        return new TestResult(
            $provider->title,
            $response->model,
            trim($response->text()),
            array_sum(array_map(static fn(Usage $usage): int => $usage->input + $usage->cacheRead + $usage->cacheWrite, $response->usage)),
            array_sum(array_map(static fn(Usage $usage): int => $usage->output, $response->usage)),
            ShowAiUsage::dollars($cost),
            round((hrtime(true) - $started) / 1e9, 2),
        );
    }
}
