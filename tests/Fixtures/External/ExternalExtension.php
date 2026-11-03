<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Fixtures\External;

use Frootbox\Core\Consent\ExternalService;
use Frootbox\Core\Extension\Extension;
use Frootbox\Core\Extension\Registry;

/**
 * Registers a service of another provider (decision 0224).
 */
final class ExternalExtension implements Extension
{
    public function register(Registry $registry): void
    {
        $registry->add(ExternalService::class, new ExternalService('acme/external/osm', 'OpenStreetMap', 'OpenStreetMap Foundation', 'https://osmfoundation.org/wiki/Privacy_Policy'));
    }
}
