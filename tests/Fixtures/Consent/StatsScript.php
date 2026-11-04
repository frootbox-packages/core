<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Fixtures\Consent;

use Frootbox\Core\Consent\ConsentMarkup;
use Frootbox\Core\View\BuildingHead;

/**
 * Puts the script of the statistics into the head, inactive until the
 * visitor agrees.
 */
final readonly class StatsScript
{
    public function __construct(private ConsentMarkup $consent) {}

    public function __invoke(BuildingHead $event): void
    {
        $event->head->add($this->consent->deferred(ConsentExtension::SERVICE, '<script>window.acmeStats = (window.acmeStats ?? 0) + 1;</script>'));
    }
}
