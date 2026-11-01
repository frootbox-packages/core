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
        /** Tells error pages which case it is, e.g. "site-protected" */
        public readonly ?string $reason = null,
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
    /**
     * A preview link that expired or was revoked (decision 0152). No
     * silent fallback to the published website.
     */
    public static function previewExpired(): self
    {
        return new self(410, 'This preview link has expired or was revoked.', ['X-Robots-Tag' => 'noindex, nofollow'], reason: 'preview-expired');
    }

    /**
     * The website is protected by a password and the visitor has not
     * entered it (decision 0153).
     */
    public static function siteProtected(string $path, bool $password = true): self
    {
        return $password
            ? new self(401, \sprintf('The website is protected, "%s" needs the password.', $path), ['X-Robots-Tag' => 'noindex, nofollow'], reason: 'site-protected')
            // Forced by SITE_PROTECT without a password: only users and preview links get in (decision 0154).
            : new self(401, \sprintf('The website is protected, "%s" is only for signed-in users.', $path), ['X-Robots-Tag' => 'noindex, nofollow'], reason: 'site-protected-closed');
    }

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
