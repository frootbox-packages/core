<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Settings;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Action\Schema\Description;

/**
 * @implements Action<SettingsDetails>
 */
#[AsAction('settings.update', 'Changes settings of an extension that are not secrets; a value equal to the default resets it')]
final readonly class UpdateSettings implements Action
{
    /**
     * @param array<string, mixed> $values
     */
    public function __construct(
        #[Description('The Composer package of the extension, e.g. "acme/shop"')]
        public string $package,
        #[Description('New values by name, see settings.show')]
        public array $values,
    ) {}

    public function handle(Settings $settings): SettingsDetails
    {
        $settings->update('settings.update', $this->package, $this->values);

        return $settings->details($this->package);
    }
}
