<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Hub;

use Frootbox\Core\Action\AsQuery;
use Frootbox\Core\Action\Query;

/**
 * The connection to the hub (decision 0284), with the time the hub last
 * read the status. What it reads is status.show.
 *
 * @implements Query<HubDetails>
 */
#[AsQuery('system.hub-show', 'Shows the connection of the website to the hub')]
final readonly class ShowHub implements Query
{
    public function handle(Hub $hub): HubDetails
    {
        return $hub->details();
    }
}
