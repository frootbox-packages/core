<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Action;

use Attribute;

/**
 * Describes an action for the runner, the REST API, MCP and the AI.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class AsAction
{
    /**
     * @param string      $name        Unique, lowercase, with a dot: "page.create"
     * @param string      $description What the action does, in one sentence. The AI reads it.
     * @param string|null $permission  Needed permission, by default the name
     * @param bool        $public      Anyone may run it, e.g. sending a contact form
     * @param bool        $signedIn    Every signed-in user may run it, e.g. signing out
     * @param bool        $undoable    False if it has effects outside the database, such as sending a mail
     */
    public function __construct(
        public string $name,
        public string $description,
        public ?string $permission = null,
        public bool $public = false,
        public bool $signedIn = false,
        public bool $undoable = true,
    ) {}
}
