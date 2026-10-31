<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Error;

use Frootbox\Core\Action\AccessDeniedException;
use Frootbox\Core\Action\ActionException;
use Frootbox\Core\Action\InvalidInputException;
use Frootbox\Core\Action\UndoException;
use Frootbox\Core\Action\UnknownActionException;
use Frootbox\Core\Http\Exception\HttpException;
use Frootbox\Database\EntityNotFoundException;
use Throwable;

/**
 * An error as HTTP status and problem details after RFC 9457, e.g. for
 * application/problem+json in the REST API.
 *
 * Messages of server errors are only shown in development, because they
 * may contain internals.
 */
final readonly class Problem
{
    private const array TITLES = [
        400 => 'Bad Request',
        401 => 'Unauthorized',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        409 => 'Conflict',
        415 => 'Unsupported Media Type',
        422 => 'Unprocessable Content',
        429 => 'Too Many Requests',
        500 => 'Internal Server Error',
        503 => 'Service Unavailable',
    ];

    private const array CODES = [
        400 => 'bad-request',
        401 => 'unauthorized',
        403 => 'access-denied',
        404 => 'not-found',
        405 => 'method-not-allowed',
        409 => 'conflict',
        415 => 'unsupported-media-type',
        422 => 'unprocessable',
        429 => 'too-many-requests',
        503 => 'unavailable',
    ];

    /**
     * @param array<string, string> $headers
     * @param array<string, string> $errors  Message by JSON pointer, e.g. "/email"
     */
    public function __construct(
        public int $status,
        public string $code,
        public string $detail,
        public array $headers = [],
        public array $errors = [],
    ) {}

    public static function from(Throwable $throwable, bool $showDetails): self
    {
        return match (true) {
            $throwable instanceof HttpException => new self(
                $throwable->status,
                self::CODES[$throwable->status] ?? ($throwable->status >= 500 ? 'server-error' : 'error'),
                $throwable->status < 500 || $showDetails ? $throwable->getMessage() : self::title($throwable->status),
                $throwable->headers,
            ),
            $throwable instanceof AccessDeniedException => $throwable->signInRequired
                ? new self(401, 'unauthorized', $throwable->getMessage(), ['WWW-Authenticate' => 'Bearer'])
                : new self(403, 'access-denied', $throwable->getMessage()),
            $throwable instanceof InvalidInputException => new self(422, 'invalid-input', $throwable->getMessage(), errors: $throwable->errors),
            $throwable instanceof UnknownActionException,
            $throwable instanceof EntityNotFoundException => new self(404, 'not-found', $throwable->getMessage()),
            $throwable instanceof UndoException => new self(409, 'conflict', $throwable->getMessage()),
            $throwable instanceof ActionException => new self(422, 'unprocessable', $throwable->getMessage()),
            default => new self(500, 'server-error', $showDetails ? $throwable->getMessage() : self::title(500)),
        };
    }

    public static function title(int $status): string
    {
        return self::TITLES[$status] ?? ($status >= 500 ? self::TITLES[500] : 'Error');
    }

    /**
     * The body of application/problem+json.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $body = [
            'type' => 'about:blank',
            'title' => self::title($this->status),
            'status' => $this->status,
            'detail' => $this->detail,
            'code' => $this->code,
        ];

        if ([] !== $this->errors) {
            $body['errors'] = array_map(
                static fn(string $pointer, string $message): array => ['pointer' => '' === $pointer ? '/' : $pointer, 'message' => $message],
                array_keys($this->errors),
                array_values($this->errors),
            );
        }

        return $body;
    }
}
