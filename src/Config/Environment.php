<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Config;

/**
 * Runtime environment, set with APP_ENV.
 *
 * Development disables all caches and shows detailed error pages.
 */
enum Environment: string
{
    case Development = 'dev';
    case Production = 'prod';

    public function isDevelopment(): bool
    {
        return self::Development === $this;
    }
}
