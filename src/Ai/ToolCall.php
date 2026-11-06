<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Ai;

/**
 * The model asks to run a tool.
 */
final readonly class ToolCall implements Part
{
    /**
     * @param array<string, mixed> $input
     */
    public function __construct(
        /** The id of the provider, to answer with a ToolResult */
        public string $id,
        /** The tool name, see Tools::name() */
        public string $name,
        public array $input,
    ) {}
}
