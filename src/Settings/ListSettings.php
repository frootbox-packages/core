<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Settings;

use Frootbox\Core\Action\AsQuery;
use Frootbox\Core\Action\Query;

/**
 * @implements Query<list<SettingsDetails>>
 */
#[AsQuery('settings.list', 'Lists the settings of all extensions; secrets only say whether they are set')]
final readonly class ListSettings implements Query
{
    /**
     * @return list<SettingsDetails>
     */
    public function handle(Settings $settings): array
    {
        return $settings->all();
    }
}
