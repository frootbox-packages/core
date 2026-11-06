<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Ai;

/**
 * A request to the model of the website (decision 0294). The model comes
 * from the settings of the provider, a feature cannot choose it.
 */
final readonly class Request
{
    /**
     * @param list<Message> $messages
     * @param list<Tool>    $tools
     */
    public function __construct(
        /** What the model is and does, the same for every request of a feature, so it can be cached */
        public string $system,
        public array $messages,
        public array $tools = [],
        /** The longest answer in tokens */
        public int $maxTokens = 4096,
        public Effort $effort = Effort::Medium,
        /** Whether the provider keeps the start (tools and system) for the next request, cheaper when it repeats */
        public bool $cache = true,
    ) {}
}
