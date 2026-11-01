<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Site;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;

/**
 * @implements Action<SiteDetails>
 */
#[AsAction('site.discard', 'Discards the draft of the fields of the website; the published values stay')]
final readonly class DiscardSite implements Action
{
    public function handle(SiteEditor $editor): SiteDetails
    {
        return $editor->discard('site.discard');
    }
}
