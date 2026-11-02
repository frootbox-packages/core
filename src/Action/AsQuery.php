<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Action;

use Attribute;

/**
 * Describes a query for the runner, the REST API, MCP and the AI.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class AsQuery
{
    /**
     * @param string      $name        Unique, lowercase, with a dot: "page.list"
     * @param string      $description What the query returns, in one sentence. The AI reads it.
     * @param string|null $permission  Needed permission, by default the name
     * @param bool        $public      Anyone may run it
     * @param bool        $signedIn    Every signed-in user may run it
     * @param bool        $preview     Guests with a valid preview link may run it as well (decision 0156)
     */
    public function __construct(
        public string $name,
        public string $description,
        public ?string $permission = null,
        public bool $public = false,
        public bool $signedIn = false,
        public bool $preview = false,
    ) {}
}
