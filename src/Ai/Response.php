<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Ai;

/**
 * The answer of the model.
 */
final readonly class Response
{
    /**
     * @param list<Part>  $parts Text, ToolCall and Opaque
     * @param list<Usage> $usage one per model that worked on it
     */
    public function __construct(
        public array $parts,
        public StopReason $stop,
        public array $usage,
        /** The model that gave the answer */
        public string $model,
    ) {}

    /**
     * The text of the answer, its parts one after the other.
     */
    public function text(): string
    {
        return implode('', array_map(static fn(Text $text): string => $text->text, array_values(array_filter($this->parts, static fn(Part $part): bool => $part instanceof Text))));
    }

    /**
     * @return list<ToolCall>
     */
    public function toolCalls(): array
    {
        return array_values(array_filter($this->parts, static fn(Part $part): bool => $part instanceof ToolCall));
    }
}
