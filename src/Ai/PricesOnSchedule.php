<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Ai;

use Frootbox\Core\Schedule\ScheduleRan;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Asks the hub for the prices of the AI after a run of the schedule, only
 * when it is due (decision 0297). A failure only waits for the next try.
 */
final readonly class PricesOnSchedule
{
    public function __construct(
        private PriceList $prices,
        private LoggerInterface $logger,
    ) {}

    public function __invoke(ScheduleRan $event): void
    {
        try {
            if ($this->prices->due($event->now)) {
                $this->prices->refresh($event->now);
            }
        } catch (Throwable $error) {
            $this->logger->warning('The prices of the AI could not be read from the hub: {message}', ['message' => $error->getMessage(), 'exception' => $error]);
        }
    }
}
