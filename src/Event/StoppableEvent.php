<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Event;

use Psr\EventDispatcher\StoppableEventInterface;

/**
 * Base class for events that a listener may stop, so that later listeners
 * no longer see them.
 */
abstract class StoppableEvent implements StoppableEventInterface
{
    private bool $stopped = false;

    public function stopPropagation(): void
    {
        $this->stopped = true;
    }

    public function isPropagationStopped(): bool
    {
        return $this->stopped;
    }
}
