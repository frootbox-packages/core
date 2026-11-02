<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Auth;

/**
 * Who runs an action: the system itself (console, cron), a signed-in user
 * or a guest.
 */
final readonly class Actor
{
    private function __construct(
        public ActorType $type,
        public ?int $id,
        public string $name,
    ) {}

    public static function system(): self
    {
        return new self(ActorType::System, null, 'System');
    }

    public static function guest(): self
    {
        return new self(ActorType::Guest, null, 'Guest');
    }

    public static function user(int $id, string $name): self
    {
        return new self(ActorType::User, $id, $name);
    }
}
