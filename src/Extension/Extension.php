<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Extension;

/**
 * Optional entry point of an extension, named in frootbox.yaml:
 *
 *     extension: Acme\Website\WebsiteExtension
 *
 * register() runs on every request and console call, before the container
 * is built. It only announces things, services are created later and only
 * when needed.
 */
interface Extension
{
    public function register(Registry $registry): void;
}
