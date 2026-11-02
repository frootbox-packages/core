<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Mail;

use Frootbox\Core\Config\Config;
use Psr\Log\LoggerInterface;

/**
 * Sends mails (decision 0222). Modules put them in the queue with the
 * job SendMail instead of sending during a request. The transport comes
 * from MAILER_DSN: native://default (PHP's mail(), the default live),
 * log://default (the default in development), null://default or
 * memory://default for tests.
 */
final class Mailer
{
    private ?Transport $transport = null;

    public function __construct(
        private readonly Config $config,
        private readonly LoggerInterface $logger,
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
     * The sender: mail.from in config/app.yaml, else noreply@ and the host of app.url.
     */
    public function from(): string
    {
        $from = $this->config->get('mail.from');

        if (\is_string($from) && null !== self::address($from)) {
            return $from;
        }

        $url = $this->config->get('app.url');
        $host = \is_string($url) ? parse_url($url, \PHP_URL_HOST) : null;

        return 'noreply@' . (\is_string($host) && '' !== $host ? $host : 'localhost');
    }

    public function transport(): Transport
    {
        if (null !== $this->transport) {
            return $this->transport;
        }

        $dsn = $this->config->env('MAILER_DSN') ?? ($this->config->environment->isDevelopment() ? 'log://default' : 'native://default');
        $scheme = strtolower((string) parse_url($dsn, \PHP_URL_SCHEME));

        return $this->transport = match ($scheme) {
            'native' => new NativeTransport(),
            'log' => new LogTransport($this->logger),
            'null' => new MemoryTransport(keep: false),
            'memory' => new MemoryTransport(),
            default => throw new MailException(\sprintf('MAILER_DSN "%s" is not supported. Use native://default, log://default or null://default.', $scheme)),
        };
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
