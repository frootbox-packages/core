<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Hub;

/**
 * Whether an address is local (decision 0284): such a website, e.g. in
 * the workbench, does not connect to the hub on the internet, only to one
 * that runs locally too.
 */
final class HubAddress
{
    private const array LOCAL_SUFFIXES = ['.localhost', '.test', '.local', '.internal', '.example', '.invalid', '.home.arpa'];

    public static function isLocal(string $url): bool
    {
        $host = parse_url($url, \PHP_URL_HOST);

        if (!\is_string($host) || '' === $host) {
            return true;
        }

        $host = strtolower(trim($host, '[]'));

        if (false !== filter_var($host, \FILTER_VALIDATE_IP)) {
            return false === filter_var($host, \FILTER_VALIDATE_IP, \FILTER_FLAG_NO_PRIV_RANGE | \FILTER_FLAG_NO_RES_RANGE);
        }

        if ('localhost' === $host || !str_contains($host, '.')) {
            return true;
        }

        foreach (self::LOCAL_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return true;
            }
        }

        return false;
    }
}
