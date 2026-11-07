<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\I18n;

use Frootbox\Core\Action\Schema\Description;
use Frootbox\Core\Action\Schema\Title;
use Frootbox\Core\Settings\AsSettings;

/**
 * How the website addresses visitors by default (decision 0316).
 */
#[AsSettings('Form of address', 'How texts of the website address visitors, in languages that tell the two apart, e.g. German "Du" or "Sie".', section: 'formality')]
final readonly class FormalitySettings
{
    public function __construct(
        #[Title('Address visitors formally'), Description('E.g. "Sie" instead of "Du" in German. An extension may choose for a visitor, such as the shop for a signed-in customer.')]
        public bool $formal = false,
    ) {}
}
