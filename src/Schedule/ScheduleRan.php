<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Schedule;

use DateTimeImmutable;

/**
 * The scheduler ran, from cron or a page request, about once a minute.
 * Extensions listen for work that is due now and then, e.g. deleting
 * old form entries (decision 0222). A listener changes data only through
 * actions run as the system and should look first whether anything is
 * due at all.
 */
final readonly class ScheduleRan
{
    public function __construct(public DateTimeImmutable $now) {}
}
