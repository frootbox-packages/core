<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Design;

use Frootbox\Core\Action\AsQuery;
use Frootbox\Core\Action\Query;

/**
 * @implements Query<DesignDetails>
 */
#[AsQuery('design.show', 'Returns the tokens of the active theme that editors may change, with the values of draft, published design and theme')]
final readonly class ShowDesign implements Query
{
    public function handle(DesignEditor $editor): DesignDetails
    {
        return $editor->details();
    }
}
