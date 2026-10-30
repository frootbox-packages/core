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
