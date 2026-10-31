<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Action;

/**
 * Writes that must stand even if the action fails, e.g. a failed sign-in
 * that counts for throttling (decision 0115). An action registers them;
 * the Runner runs them once the outermost action has ended, after its
 * transaction was committed or rolled back.
 *
 * Only for bookkeeping: they are not recorded for undoing and do not
 * clear the page cache. Business data changes only through actions.
 */
final class Bookkeeping
{
    /** @var list<callable(): mixed> */
    private array $pending = [];

    /**
     * @param callable(): mixed $write
     */
    public function afterwards(callable $write): void
    {
        $this->pending[] = $write;
    }

    /**
     * Runs and forgets the pending writes, in the order they came.
     *
     * @internal for the Runner
     */
    public function flush(): void
    {
        while ([] !== $this->pending) {
            $write = array_shift($this->pending);
            $write();
        }
    }
}
