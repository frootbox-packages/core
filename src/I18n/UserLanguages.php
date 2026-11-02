<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\I18n;

/**
 * The language a user chose for the backend (decision 0161).
 */
interface UserLanguages
{
    /** The code, or null for automatic or an unknown user. */
    public function of(int $userId): ?string;
}
