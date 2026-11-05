<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Mcp;

use Frootbox\Core\Action\Catalog;
use Frootbox\Core\Action\Descriptor;
use Frootbox\Core\Auth\Actor;
use Frootbox\Core\Auth\Gate;

/**
 * The MCP tools of an actor: every action and query of the catalog it may
 * run, except actions for visitors such as signing in or sending a form.
 *
 * Tool names only use letters, digits, "_" and "-", which every client
 * and model accepts: "page.create" is "page_create",
 * "frootbox/news/article.create" is "frootbox__news__article_create".
 */
final readonly class Tools
{
    public function __construct(
        private Catalog $catalog,
        private Gate $gate,
    ) {}

    /**
     * @return array<string, Descriptor> by tool name, sorted by name
     */
    public function of(Actor $actor): array
    {
        $tools = [];

        foreach ($this->catalog->all() as $descriptor) {
            if (($descriptor->public && $descriptor->isAction()) || !$this->gate->allows($actor, $descriptor)) {
                continue;
            }

            // Two packages that only differ in "." and "_" would meet here; the first wins.
            $tools[self::name($descriptor->name)] ??= $descriptor;
        }

        return $tools;
    }

    public static function name(string $name): string
    {
        return strtr($name, ['/' => '__', '.' => '_']);
    }

    /**
     * The definition for tools/list.
     *
     * @return array<string, mixed>
     */
    public static function definition(string $name, Descriptor $descriptor): array
    {
        return [
            'name' => $name,
            'title' => $descriptor->name,
            'description' => $descriptor->description,
            'inputSchema' => $descriptor->schema(),
            'annotations' => $descriptor->isAction()
                ? ['readOnlyHint' => false, 'destructiveHint' => $descriptor->confirm]
                : ['readOnlyHint' => true],
        ];
    }
}
