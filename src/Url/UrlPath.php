<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Url;

use Normalizer;

/**
 * Paths with letters of any script (decision 0158): stored and compared
 * as Unicode in NFC, over HTTP percent-encoded.
 */
final class UrlPath
{
    /**
     * The path of a request as text, "/من-نحن" for "/%D9%85%D9%86-…";
     * null if it is no valid UTF-8.
     */
    public static function decode(string $path): ?string
    {
        $decoded = rawurldecode($path);

        if (!mb_check_encoding($decoded, 'UTF-8')) {
            return null;
        }

        $normalized = Normalizer::normalize($decoded, Normalizer::FORM_C);

        return \is_string($normalized) ? $normalized : $decoded;
    }

    /**
     * A path for a link or a header: letters beyond ASCII, spaces and
     * control characters percent-encoded with upper case hex. Slashes,
     * a query and characters already encoded stay as they are.
     */
    public static function encode(string $path): string
    {
        return (string) preg_replace_callback('/[^\x21-\x7E]/', static fn(array $byte): string => rawurlencode($byte[0]), $path);
    }
}
