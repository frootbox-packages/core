<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Ai;

/**
 * The result of a tool for the model, in a message of the user.
 */
final readonly class ToolResult implements Part
{
    public function __construct(
        /** The id of the ToolCall it answers */
        public string $callId,
        /** JSON or a message */
        public string $content,
        public bool $error = false,
    ) {}
}
