<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Action;

use Frootbox\Core\Auth\Actor;

/**
 * Who runs an action and through which channel.
 */
final readonly class Context
{
    public function __construct(
        public Actor $actor,
        public Channel $channel,
    ) {}

    public static function system(Channel $channel = Channel::System): self
    {
        return new self(Actor::system(), $channel);
    }

    public static function guest(Channel $channel = Channel::Web): self
    {
        return new self(Actor::guest(), $channel);
    }
}
