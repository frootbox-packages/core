<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Error;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The registered error pages (decision 0132). The first that is
 * responsible for a request renders it. One that fails itself is logged
 * and skipped, so the error page of the core still shows.
 */
final readonly class ErrorPages
{
    /**
     * @param list<class-string<ErrorPage>> $classes
     */
    public function __construct(
        private array $classes,
        private ContainerInterface $container,
        private LoggerInterface $logger,
    ) {}

    public function render(ServerRequestInterface $request, int $status, Throwable $throwable, bool $showDetails): ?string
    {
        foreach ($this->classes as $class) {
            try {
                $page = $this->container->get($class);
                $html = $page instanceof ErrorPage ? $page->render($request, $status, $throwable, $showDetails) : null;
            } catch (Throwable $failure) {
                $this->logger->error('The error page {page} failed: {message}', ['page' => $class, 'message' => $failure->getMessage(), 'exception' => $failure]);

                continue;
            }

            if (null !== $html) {
                return $html;
            }
        }

        return null;
    }
}
