<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Error;

use Frootbox\Core\Auth\AuthenticationException;
use Frootbox\Core\Auth\TooManyAttemptsException;
use Frootbox\Core\Http\Exception\HttpException;
use Frootbox\Core\Site\SiteAccessMiddleware;
use Throwable;

/**
 * Renders error pages. Details are only shown in development.
 */
final class ErrorPageRenderer
{
    private const array TITLES = [
        401 => 'Diese Website ist noch nicht öffentlich',
        403 => 'Kein Zugriff',
        404 => 'Seite nicht gefunden',
        405 => 'Methode nicht erlaubt',
        410 => 'Seite nicht mehr vorhanden',
        500 => 'Es ist ein Fehler aufgetreten',
        503 => 'Vorübergehend nicht erreichbar',
    ];

    public function render(int $status, Throwable $throwable, bool $showDetails): string
    {
        $title = self::TITLES[$status] ?? ($status >= 500 ? self::TITLES[500] : 'Fehler');
        $details = $showDetails ? $this->details($throwable) : '';
        $reason = $throwable instanceof HttpException ? $throwable->reason : null;

        // The password of the website and preview links (decisions 0152, 0153).
        if ('preview-expired' === $reason) {
            $title = 'Vorschaulink abgelaufen';
            $details = '<p>Dieser Vorschaulink ist abgelaufen oder wurde zurückgezogen. Frag nach einem neuen Link.</p><p><a href="/">Zur Website</a></p>' . $details;
        } elseif ('site-protected' === $reason || 'site-protected-closed' === $reason) {
            $details = $this->passwordForm($throwable, 'site-protected' === $reason) . $details;
        }

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
            form { display: grid; gap: .5rem; max-width: 20rem; }
            input, button { font: inherit; padding: .5rem .75rem; }
            .error { color: #b00020; }
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

    private function passwordForm(Throwable $throwable, bool $password): string
    {
        if (!$password) {
            return '<p>Nur angemeldete Benutzer und Inhaber eines Vorschaulinks sehen sie.</p>';
        }

        $error = match (true) {
            $throwable->getPrevious() instanceof TooManyAttemptsException => '<p class="error" role="alert">Zu viele Versuche. Warte einen Moment und versuch es dann noch einmal.</p>',
            $throwable->getPrevious() instanceof AuthenticationException => '<p class="error" role="alert">Das Passwort stimmt nicht.</p>',
            default => '',
        };
        $field = SiteAccessMiddleware::FIELD;

        return <<<HTML
            <form method="post">
            {$error}
            <label for="{$field}">Passwort</label>
            <input id="{$field}" name="{$field}" type="password" autocomplete="current-password" required autofocus>
            <button type="submit">Weiter</button>
            </form>
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
