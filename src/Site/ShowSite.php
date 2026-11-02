<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Site;

use Frootbox\Core\Action\AsQuery;
use Frootbox\Core\Action\Query;

/**
 * @implements Query<SiteDetails>
 */
#[AsQuery('site.show', 'Returns the fields of the website the theme describes, e.g. name and address, with their values per language')]
final readonly class ShowSite implements Query
{
    public function handle(SiteEditor $editor): SiteDetails
    {
        return $editor->details();
    }
}
