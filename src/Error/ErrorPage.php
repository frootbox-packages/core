<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Error;

use Psr\Http\Message\ServerRequestInterface;
use Throwable;

/**
 * An error page of an extension, e.g. the one of the backend (decision
 * 0132). Registered with $registry->errorPage(), created through the
 * container only when an error happens.
 */
interface ErrorPage
{
    /**
     * The HTML of the page, or null if this page is not responsible for the
     * request. $showDetails is true in development only.
     */
    public function render(ServerRequestInterface $request, int $status, Throwable $throwable, bool $showDetails): ?string;
}
