<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Site;

use Frootbox\Core\Action\AsQuery;
use Frootbox\Core\Action\Query;

/**
 * Whether the website is protected by a password, for everyone signed in,
 * so the backend and the editor bar can say so (decision 0153).
 *
 * @implements Query<ProtectionStatus>
 */
#[AsQuery('site.protection', 'Returns whether the website is protected by a password, whether .env forces it and since when', signedIn: true)]
final readonly class ShowProtection implements Query
{
    public function handle(SiteProtection $protection): ProtectionStatus
    {
        return $protection->status();
    }
}
