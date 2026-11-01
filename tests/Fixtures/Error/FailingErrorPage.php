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
 * An error page that fails itself.
 */
final class FailingErrorPage implements ErrorPage
{
    public function render(ServerRequestInterface $request, int $status, Throwable $throwable, bool $showDetails): ?string
    {
        throw new \RuntimeException('The page is broken.');
    }
}
