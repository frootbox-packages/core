<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Mcp;

use RuntimeException;

/**
 * A JSON-RPC error of the MCP endpoint, with the HTTP status it is sent
 * with.
 */
final class ProtocolError extends RuntimeException
{
    public const int PARSE_ERROR = -32700;

    public const int INVALID_REQUEST = -32600;

    public const int METHOD_NOT_FOUND = -32601;

    public const int INVALID_PARAMS = -32602;

    public const int INTERNAL_ERROR = -32603;

    public const int HEADER_MISMATCH = -32020;

    public const int UNSUPPORTED_PROTOCOL_VERSION = -32022;

    public function __construct(
        public readonly int $rpcCode,
        string $message,
        public readonly int $status = 400,
        public readonly mixed $data = null,
    ) {
        parent::__construct($message);
    }

    /**
     * Bad input to a method, e.g. an unknown tool. Only malformed protocol
     * fields are a 400; clients of older versions take any other status
     * for a broken connection.
     */
    public static function invalidParams(string $message, int $status = 200): self
    {
        return new self(self::INVALID_PARAMS, $message, $status);
    }

    public static function headerMismatch(string $header, string $expected): self
    {
        return new self(self::HEADER_MISMATCH, \sprintf('The header %s must be "%s".', $header, $expected));
    }
}
