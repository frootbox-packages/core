<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Action;

/**
 * Fired after an action succeeded and its changes were saved, e.g. to
 * clear caches.
 */
final readonly class ActionExecuted
{
    /**
     * @param Action<mixed> $action
     */
    public function __construct(
        public Descriptor $descriptor,
        public Action $action,
        public mixed $result,
        public Context $context,
        public int $entryId,
    ) {}
}
