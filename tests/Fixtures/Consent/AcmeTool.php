<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Fixtures\Consent;

use Frootbox\Core\Consent\ConsentToolProvider;
use Frootbox\Core\View\BuildingHead;

/**
 * A consent tool of another provider, set up once it has a site ID.
 */
final class AcmeTool implements ConsentToolProvider
{
    public static string $site = 'site-1';

    public function ready(): bool
    {
        return '' !== self::$site;
    }

    public function head(BuildingHead $event): string
    {
        return '<script src="https://consent.example/' . self::$site . '.js"></script>';
    }
}
