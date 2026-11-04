<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Fixtures\Consent;

use Frootbox\Core\Consent\ExternalService;
use Frootbox\Core\Consent\Purpose;
use Frootbox\Core\Consent\Reach;
use Frootbox\Core\Extension\Extension;
use Frootbox\Core\Extension\Registry;
use Frootbox\Core\View\BuildingHead;

/**
 * Registers statistics of another provider on every page (decision 0264).
 */
final class ConsentExtension implements Extension
{
    public const string SERVICE = 'acme/consent/stats';

    public function register(Registry $registry): void
    {
        $registry->add(ExternalService::class, new ExternalService(self::SERVICE, 'Acme Stats', 'Acme Analytics Ltd', 'https://stats.example/privacy', Purpose::Statistics, Reach::Site));
        $registry->listen(BuildingHead::class, StatsScript::class);
    }
}
