<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Action;

/**
 * Fired after an action succeeded and its changes were saved, from the
 * backend, the REST API, the console or a seed file alike, e.g. to clear
 * caches or to tell other services. $descriptor->name is the name of the
 * action, e.g. "page.publish", $action its input, $result what it gave.
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
