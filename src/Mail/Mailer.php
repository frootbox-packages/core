<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Mail;

use Frootbox\Core\Config\Config;
use Frootbox\Core\Settings\Settings;
use Psr\Log\LoggerInterface;

/**
 * Sends mails (decisions 0222, 0269). Modules put them in the queue with
 * the job SendMail instead of sending during a request. The transport:
 *
 * 1. MAILER_DSN: smtp:// or smtps:// (symfony/mailer), native://default
 *    (PHP's mail()), log://default, null://default or memory://default
 *    for tests.
 * 2. Live, the SMTP server of the settings "frootbox/core.mail".
 * 3. native://default live, log://default in development.
 */
final class Mailer
{
    private const array SCHEMES = ['smtp', 'smtps', 'native', 'log', 'null', 'memory'];

    private ?Transport $transport = null;

    public function __construct(
        private readonly Config $config,
        private readonly LoggerInterface $logger,
        private readonly Settings $settings,
    ) {}

    /**
     * @throws MailException
     */
    public function send(Message $message): void
    {
        $to = array_values(array_filter(array_map(self::address(...), $message->to), static fn(?string $address): bool => null !== $address));

        if ([] === $to) {
            throw new MailException('The mail has no valid recipient.');
        }

        $replyTo = null === $message->replyTo ? null : self::address($message->replyTo);
        $subject = trim(str_replace(["\r", "\n"], ' ', $message->subject));

        $this->transport()->send(new Message($to, $subject, $message->text, $replyTo), $this->from());
    }

    /**
     * The sender: mail.from in config/app.yaml, else the one of the
     * settings, else noreply@ and the host of app.url.
     */
    public function from(): string
    {
        foreach ([$this->config->get('mail.from'), $this->mailSettings()->from] as $from) {
            if (\is_string($from) && null !== self::address($from)) {
                return trim($from);
            }
        }

        $url = $this->config->get('app.url');
        $host = \is_string($url) ? parse_url($url, \PHP_URL_HOST) : null;

        return 'noreply@' . (\is_string($host) && '' !== $host ? $host : 'localhost');
    }

    /**
     * How mails leave the server, without a password.
     */
    public function status(): MailStatus
    {
        $dsn = $this->config->env('MAILER_DSN');
        $settings = $this->mailSettings();
        $host = trim($settings->host);

        if (null !== $dsn) {
            $scheme = self::scheme($dsn);
            $server = parse_url($dsn, \PHP_URL_HOST);
            $port = parse_url($dsn, \PHP_URL_PORT);
            $smtp = \in_array($scheme, ['smtp', 'smtps'], true);

            return new MailStatus(
                transport: $smtp ? 'smtp' : $scheme,
                source: MailStatus::ENVIRONMENT,
                from: $this->from(),
                host: $smtp && \is_string($server) ? $server : null,
                port: $smtp && \is_int($port) ? $port : null,
                settingsIgnored: '' !== $host,
            );
        }

        if ('' !== $host && !$this->config->environment->isDevelopment()) {
            return new MailStatus('smtp', MailStatus::SETTINGS, $this->from(), $host, $settings->port);
        }

        return new MailStatus(
            transport: $this->config->environment->isDevelopment() ? 'log' : 'native',
            source: MailStatus::STANDARD,
            from: $this->from(),
            settingsIgnored: '' !== $host,
        );
    }

    /**
     * @throws MailException
     */
    public function transport(): Transport
    {
        if (null !== $this->transport) {
            return $this->transport;
        }

        $dsn = $this->config->env('MAILER_DSN');
        $status = $this->status();

        if (MailStatus::SETTINGS === $status->source) {
            return $this->transport = SmtpTransport::fromSettings($this->mailSettings());
        }

        if (null !== $dsn && !\in_array(self::scheme($dsn), self::SCHEMES, true)) {
            throw new MailException(\sprintf('MAILER_DSN "%s://…" is not supported. Use smtp://, smtps://, native://default, log://default or null://default.', self::scheme($dsn)));
        }

        return $this->transport = match ($status->transport) {
            'smtp' => SmtpTransport::fromDsn((string) $dsn),
            'native' => new NativeTransport(),
            'log' => new LogTransport($this->logger),
            'null' => new MemoryTransport(keep: false),
            default => new MemoryTransport(),
        };
    }

    private function mailSettings(): MailSettings
    {
        return $this->settings->get(MailSettings::class);
    }

    private static function scheme(string $dsn): string
    {
        return strtolower((string) parse_url($dsn, \PHP_URL_SCHEME));
    }

    /**
     * A single address without line breaks, or null.
     */
    private static function address(string $address): ?string
    {
        $address = trim($address);

        return false === filter_var($address, \FILTER_VALIDATE_EMAIL) || 1 === preg_match('/[\r\n,;]/', $address) ? null : $address;
    }
}
