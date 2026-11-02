<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Error;

use Frootbox\Core\Auth\AuthenticationException;
use Frootbox\Core\Auth\TooManyAttemptsException;
use Frootbox\Core\Http\Exception\HttpException;
use Frootbox\Core\Site\SiteAccessMiddleware;
use Throwable;

/**
 * The plain error page of the core: before the installation, for the
 * access to the website and when no other page is responsible (decisions
 * 0132, 0189). Calm like the one of the backend: the status small, title,
 * text, the technical details collapsed and only in development.
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
        $body = '';
        $reason = $throwable instanceof HttpException ? $throwable->reason : null;

        // The password of the website and preview links (decisions 0152, 0153).
        if ('preview-expired' === $reason) {
            $title = 'Vorschaulink abgelaufen';
            $body = '<p>Dieser Vorschaulink ist abgelaufen oder wurde zurückgezogen. Frag nach einem neuen Link.</p><p><a href="/">Zur Website</a></p>';
        } elseif ('site-protected' === $reason || 'site-protected-closed' === $reason) {
            $body = $this->passwordForm($throwable, 'site-protected' === $reason);
        }

        if ($showDetails) {
            $body .= ErrorDetails::html($throwable, 'Technische Details');
        }

        $details = ErrorDetails::CLASS_NAME;

        return <<<HTML
            <!doctype html>
            <html lang="de">
            <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <meta name="robots" content="noindex">
            <title>{$status} {$this->escape($title)}</title>
            <style>
            body { font: 16px/1.6 system-ui, sans-serif; max-width: 40rem; margin: 0 auto; padding: 4rem 1rem; color: #1f2328; background: #fff; }
            .status { margin: 0 0 .25rem; color: #6e7781; font-size: .875rem; }
            h1 { font-size: 1.75rem; line-height: 1.25; margin: 0 0 1rem; }
            a { color: #0b57d0; }
            form { display: grid; gap: .5rem; max-width: 20rem; }
            input, button { font: inherit; padding: .5rem .75rem; }
            .error { color: #b00020; }
            .{$details} { margin-top: 2.5rem; border-top: 1px solid #d0d7de; padding-top: 1rem; font-size: .875rem; }
            .{$details} summary { cursor: pointer; color: #6e7781; }
            .{$details}__exception { margin-top: 1rem; }
            .{$details}__exception p { margin: 0; }
            .{$details}__class, .{$details}__file { color: #6e7781; }
            .{$details}__message { font-size: 1rem; }
            .{$details} pre { background: #f6f8fa; padding: 1rem; overflow-x: auto; font-size: .8125rem; }
            @media (prefers-color-scheme: dark) {
              body { color: #e6edf3; background: #0d1117; }
              a { color: #79b8ff; }
              .{$details} pre { background: #161b22; }
            }
            </style>
            </head>
            <body>
            <main>
            <p class="status">Fehler {$status}</p>
            <h1>{$this->escape($title)}</h1>
            {$body}
            </main>
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

    private function escape(string $value): string
    {
        return htmlspecialchars($value, \ENT_QUOTES | \ENT_SUBSTITUTE);
    }
}
