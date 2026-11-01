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
#[AsAction('site.publish', 'Publishes the draft of the fields of the website; visitors see them right away')]
final readonly class PublishSite implements Action
{
    public function handle(SiteEditor $editor): SiteDetails
    {
        return $editor->publish('site.publish');
    }
}
