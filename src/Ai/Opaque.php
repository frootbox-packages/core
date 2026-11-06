<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Ai;

/**
 * A part of an answer only its provider understands, e.g. the thinking
 * of a model before a tool call. A feature keeps it in the conversation
 * and sends it back unchanged; other providers leave it out.
 */
final readonly class Opaque implements Part
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        /** The name of the provider, e.g. "frootbox/ai-anthropic" */
        public string $provider,
        public array $data,
    ) {}
}
