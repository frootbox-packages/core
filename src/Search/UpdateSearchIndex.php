<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Search;

use Frootbox\Core\Action\ActionExecuted;
use Frootbox\Core\Action\ActionLog;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Updates the index after an action from the rows it changed, in the
 * same request and without a process of its own (decision 0214). A
 * nested action records its changes with the outer one, so only the
 * outer one finds any. The search never breaks an action: an error is
 * logged and "search:rebuild" repairs the index.
 */
final readonly class UpdateSearchIndex
{
    public function __construct(
        private ActionLog $log,
        private SearchIndexer $indexer,
        private LoggerInterface $logger,
    ) {}

    public function __invoke(ActionExecuted $event): void
    {
        try {
            $this->indexer->changed($this->log->changes($event->entryId));
        } catch (Throwable $error) {
            $this->logger->error('The search index could not be updated after {action}: {message}', [
                'action' => $event->descriptor->name,
                'message' => $error->getMessage(),
                'exception' => $error,
            ]);
        }
    }
}
