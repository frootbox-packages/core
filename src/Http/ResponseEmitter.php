<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Http;

use Psr\Http\Message\ResponseInterface;

/**
 * Sends a PSR-7 response to the client.
 */
final class ResponseEmitter
{
    private const int CHUNK_SIZE = 8192;

    public function emit(ResponseInterface $response): void
    {
        if (!headers_sent()) {
            header(
                \sprintf('HTTP/%s %d %s', $response->getProtocolVersion(), $response->getStatusCode(), $response->getReasonPhrase()),
                true,
                $response->getStatusCode(),
            );

            foreach ($response->getHeaders() as $name => $values) {
                foreach ($values as $value) {
                    header(\sprintf('%s: %s', $name, $value), false);
                }
            }
        }

        $body = $response->getBody();

        if ($body->isSeekable()) {
            $body->rewind();
        }

        while (!$body->eof()) {
            echo $body->read(self::CHUNK_SIZE);
        }
    }
}
