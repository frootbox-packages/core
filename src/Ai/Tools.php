<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Ai;

use Frootbox\Core\Action\Descriptor;
use Frootbox\Core\Auth\Actor;
use Frootbox\Core\Mcp\Tools as McpTools;

/**
 * The tools of the AI (decision 0294): the same as over MCP, the actions
 * and queries the person may run, without ai.* so the AI never calls
 * itself. Running them comes with the assistant.
 */
final readonly class Tools
{
    public function __construct(private McpTools $tools) {}

    /**
     * @return array<string, Descriptor> by tool name
     */
    public function descriptors(Actor $actor): array
    {
        return array_filter($this->tools->of($actor), static fn(Descriptor $descriptor): bool => !str_starts_with($descriptor->name, 'ai.'));
    }

    /**
     * @return list<Tool>
     */
    public function of(Actor $actor): array
    {
        $tools = [];

        foreach ($this->descriptors($actor) as $name => $descriptor) {
            $tools[] = new Tool($name, $descriptor->description, $descriptor->schema());
        }

        return $tools;
    }
}
