<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Site;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Action\Schema\Range;

/**
 * @implements Action<SiteDetails>
 */
#[AsAction('site.restore', 'Makes a new draft of the fields of the website from an earlier version; site.publish puts it online')]
final readonly class RestoreSite implements Action
{
    public function __construct(
        #[Range(min: 1)]
        public int $version,
    ) {}

    public function handle(SiteEditor $editor): SiteDetails
    {
        return $editor->restore('site.restore', $this->version);
    }
}
