<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Url;

use Frootbox\Core\Action\AsQuery;
use Frootbox\Core\Action\Query;

/**
 * @implements Query<list<Redirect>>
 */
#[AsQuery('redirect.list', 'Returns the manual redirects by path')]
final readonly class ListRedirects implements Query
{
    /**
     * @return list<Redirect>
     */
    public function handle(Redirects $redirects): array
    {
        return $redirects->fetch(['order' => 'path'])->all();
    }
}
