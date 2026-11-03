<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Config;

/**
 * The time zone of the website from "app.timezone" in config/app.yaml,
 * e.g. "Europe/Berlin" (decision 0227). Times are kept and shown in it,
 * and days begin and end in it, also on a server that runs in UTC.
 * Without the key PHP keeps its own.
 */
final class TimeZone
{
    public const string KEY = 'app.timezone';

    /**
     * @throws ConfigException for a name PHP does not know
     */
    public static function apply(Config $config): void
    {
        $zone = $config->string(self::KEY, '');

        if ('' === $zone) {
            return;
        }

        if (!\in_array($zone, timezone_identifiers_list(), true)) {
            throw new ConfigException(\sprintf('"%s" in config/app.yaml must be a time zone like "Europe/Berlin", not "%s".', self::KEY, $zone));
        }

        date_default_timezone_set($zone);
    }
}
