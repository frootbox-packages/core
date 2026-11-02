<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Fixtures\Error;

use Frootbox\Core\Error\ErrorPage;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

/**
 * An error page that names the status.
 */
final class StatusErrorPage implements ErrorPage
{
    public function render(ServerRequestInterface $request, int $status, Throwable $throwable, bool $showDetails): string
    {
        return 'Status ' . $status . ($showDetails ? ': ' . $throwable->getMessage() : '');
    }
}
