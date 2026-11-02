<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Settings;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Action\Schema\Description;
use Frootbox\Core\Action\Schema\Sensitive;

/**
 * Like a password, a secret cannot be undone and is never shown again.
 *
 * @implements Action<SettingsDetails>
 */
#[AsAction('settings.set-secret', 'Sets or removes a secret setting of an extension such as an API key, stored encrypted', undoable: false)]
final readonly class SetSecret implements Action
{
    public function __construct(
        #[Description('The Composer package of the extension, e.g. "acme/shop"')]
        public string $package,
        public string $name,
        #[Description('The secret, null or empty to remove it')]
        #[Sensitive]
        public ?string $value,
    ) {}

    public function handle(Settings $settings): SettingsDetails
    {
        $settings->setSecret('settings.set-secret', $this->package, $this->name, $this->value);

        return $settings->details($this->package);
    }
}
