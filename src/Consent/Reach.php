<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Consent;

/**
 * Where a service loads (decision 0264).
 */
enum Reach: string
{
    /** At its content, behind a placeholder; the visitor agrees there */
    case Content = 'content';

    /** On every page, e.g. a script for statistics; the banner asks */
    case Site = 'site';
}
