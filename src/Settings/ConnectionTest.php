<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Settings;

use Frootbox\Core\Support\Message;

/**
 * Checks the settings of an extension against the service they reach,
 * e.g. address, client and secret of an API (decision 0353). Named by
 * #[AsSettings(test: …)], made by the container, run by settings.test.
 * It only reads: nothing is booked, sent or stored.
 */
interface ConnectionTest
{
    /**
     * A short sentence for the person on success, e.g. "Connected, {count} courses".
     *
     * @throws ConnectionFailed with a sentence that says what is wrong
     */
    public function run(): Message;
}
