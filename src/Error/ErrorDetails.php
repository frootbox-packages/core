<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Error;

use Throwable;

/**
 * The technical details of an error, collapsed below an error page:
 * exception, message, file and stack trace of each one in the chain. Only
 * in development, never live (decisions 0132, 0189).
 */
final class ErrorDetails
{
    public const string CLASS_NAME = 'fb-error-details';

    public static function html(Throwable $throwable, string $summary): string
    {
        $sections = '';

        for ($current = $throwable; null !== $current; $current = $current->getPrevious()) {
            $sections .= \sprintf(
                '<section class="%1$s__exception"><p class="%1$s__class">%2$s</p><p class="%1$s__message">%3$s</p><p class="%1$s__file">%4$s:%5$d</p><pre>%6$s</pre></section>',
                self::CLASS_NAME,
                self::escape($current::class),
                self::escape($current->getMessage()),
                self::escape($current->getFile()),
                $current->getLine(),
                self::escape($current->getTraceAsString()),
            );
        }

        return \sprintf('<details class="%s"><summary>%s</summary>%s</details>', self::CLASS_NAME, self::escape($summary), $sections);
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, \ENT_QUOTES | \ENT_SUBSTITUTE);
    }
}
