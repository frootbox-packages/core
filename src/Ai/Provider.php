<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Ai;

/**
 * An AI provider an extension brings (decision 0294):
 *
 *     $registry->add(Provider::class, new Provider(
 *         'acme/ai-mistral', 'Mistral', MistralClient::class,
 *     ));
 *
 * The container builds the client, so it can ask for its settings.
 */
final readonly class Provider
{
    /**
     * @param class-string<ProviderClient> $client
     */
    public function __construct(
        /** The package, e.g. "frootbox/ai-anthropic" */
        public string $name,
        /** The name people know, e.g. "Anthropic" */
        public string $title,
        public string $client,
    ) {}
}
