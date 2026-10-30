<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Error;

use Throwable;

/**
 * Renders error pages. Details are only shown in development.
 */
final class ErrorPageRenderer
{
    private const array TITLES = [
        403 => 'Kein Zugriff',
        404 => 'Seite nicht gefunden',
        405 => 'Methode nicht erlaubt',
        500 => 'Es ist ein Fehler aufgetreten',
        503 => 'Vorübergehend nicht erreichbar',
    ];

    public function render(int $status, Throwable $throwable, bool $showDetails): string
    {
        $title = self::TITLES[$status] ?? ($status >= 500 ? self::TITLES[500] : 'Fehler');
        $details = $showDetails ? $this->details($throwable) : '';

        return <<<HTML
            <!doctype html>
            <html lang="de">
            <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <meta name="robots" content="noindex">
            <title>{$status} {$this->escape($title)}</title>
            <style>
            body { font: 16px/1.6 system-ui, sans-serif; max-width: 60rem; margin: 4rem auto; padding: 0 1rem; color: #222; }
            h1 { font-size: 1.75rem; margin: 0 0 1rem; }
            .status { color: #888; font-weight: 400; }
            .exception { border-top: 1px solid #ddd; padding-top: 1rem; margin-top: 2rem; }
            .class { color: #888; font-size: .875rem; }
            .message { font-size: 1.125rem; margin: .25rem 0; }
            pre { background: #f5f5f5; padding: 1rem; overflow-x: auto; font-size: .8125rem; }
            </style>
            </head>
            <body>
            <h1><span class="status">{$status}</span> {$this->escape($title)}</h1>
            {$details}
            </body>
            </html>
            HTML;
    }

    private function details(Throwable $throwable): string
    {
        $html = '';

        for ($current = $throwable; null !== $current; $current = $current->getPrevious()) {
            $html .= \sprintf(
                '<section class="exception"><div class="class">%s</div><p class="message">%s</p><p>%s:%d</p><pre>%s</pre></section>',
                $this->escape($current::class),
                $this->escape($current->getMessage()),
                $this->escape($current->getFile()),
                $current->getLine(),
                $this->escape($current->getTraceAsString()),
            );
        }

        return $html;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, \ENT_QUOTES | \ENT_SUBSTITUTE);
    }
}
