<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Site;

use Frootbox\Core\Action\AsQuery;
use Frootbox\Core\Action\Query;

/**
 * @implements Query<list<SiteVersion>>
 */
#[AsQuery('site.versions', 'Lists the published versions of the fields of the website, the newest first')]
final readonly class ListSiteVersions implements Query
{
    /**
     * @return list<SiteVersion>
     */
    public function handle(SiteEditor $editor): array
    {
        return $editor->versions();
    }
}
