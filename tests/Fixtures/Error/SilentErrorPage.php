<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Fixtures\Error;

use Frootbox\Core\Error\ErrorPage;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

/**
 * An error page that is never responsible.
 */
final class SilentErrorPage implements ErrorPage
{
    public function render(ServerRequestInterface $request, int $status, Throwable $throwable, bool $showDetails): ?string
    {
        return null;
    }
}
