<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Http\Exception;

use RuntimeException;
use Throwable;

/**
 * An error that maps to an HTTP status code, e.g. 404.
 */
final class HttpException extends RuntimeException
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly int $status,
        string $message = '',
        public readonly array $headers = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $previous);
    }

    public static function notFound(string $path): self
    {
        return new self(404, \sprintf('No page found for "%s".', $path));
    }

    /**
     * The page was there, but is deleted or has no translation any more.
     */
    public static function gone(string $path): self
    {
        return new self(410, \sprintf('The page at "%s" is gone.', $path));
    }

    /**
     * The page is locked for visitors (decision 0117).
     */
    public static function locked(string $path): self
    {
        return new self(503, \sprintf('The page at "%s" is locked for visitors.', $path), ['X-Robots-Tag' => 'noindex']);
    }

    /**
     * @param list<string> $allowedMethods
     */
    public static function methodNotAllowed(string $method, array $allowedMethods): self
    {
        return new self(
            405,
            \sprintf('Method %s is not allowed. Allowed: %s.', $method, implode(', ', $allowedMethods)),
            ['Allow' => implode(', ', $allowedMethods)],
        );
    }
}
