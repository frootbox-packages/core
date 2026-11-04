<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Consent;

use Frootbox\Core\View\BuildingHead;

/**
 * What a consent tool brings to every page (decision 0264).
 */
interface ConsentToolProvider
{
    /**
     * Whether it is set up, e.g. has the ID of the website in the tool.
     * Only a ready tool takes over.
     */
    public function ready(): bool;

    /**
     * Tags for the head, mostly the script of the tool. It loads before
     * any consent, as the tool asks for it. Values must be escaped.
     */
    public function head(BuildingHead $event): string;
}
