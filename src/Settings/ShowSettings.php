<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Settings;

use Frootbox\Core\Action\AsQuery;
use Frootbox\Core\Action\Query;
use Frootbox\Core\Action\Schema\Description;

/**
 * @implements Query<SettingsDetails>
 */
#[AsQuery('settings.show', 'Returns the settings of one extension; secrets only say whether they are set')]
final readonly class ShowSettings implements Query
{
    public function __construct(
        #[Description('The Composer package of the extension, e.g. "acme/shop", with a section "acme/shop.mail"')]
        public string $package,
    ) {}

    public function handle(Settings $settings): SettingsDetails
    {
        return $settings->details($this->package);
    }
}
