<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Design;

use Frootbox\Core\Action\AsQuery;
use Frootbox\Core\Action\Query;

/**
 * @implements Query<list<DesignVersion>>
 */
#[AsQuery('design.versions', 'Lists the published versions of the design, the newest first')]
final readonly class ListDesignVersions implements Query
{
    /**
     * @return list<DesignVersion>
     */
    public function handle(DesignEditor $editor): array
    {
        return $editor->versions();
    }
}
