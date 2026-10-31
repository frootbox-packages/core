<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\User;

use Frootbox\Core\Action\AsQuery;
use Frootbox\Core\Action\Catalog;
use Frootbox\Core\Action\Query;

/**
 * Every permission a role can grant, from the actions and queries of the
 * core and all extensions (decision 0115). Those every guest or every
 * signed-in user has are left out.
 *
 * @implements Query<list<array{name: string, description: string, kind: string}>>
 */
#[AsQuery('permission.list', 'Lists every permission a role can grant, with a description', permission: 'role.list')]
final readonly class ListPermissions implements Query
{
    /**
     * @return list<array{name: string, description: string, kind: string}>
     */
    public function handle(Catalog $catalog): array
    {
        $permissions = [];

        foreach ($catalog->all() as $descriptor) {
            if ($descriptor->public || $descriptor->signedIn) {
                continue;
            }

            $permissions[$descriptor->permission] ??= [
                'name' => $descriptor->permission,
                'description' => $descriptor->description,
                'kind' => $descriptor->isAction() ? 'action' : 'query',
            ];
        }

        ksort($permissions);

        return array_values($permissions);
    }
}
