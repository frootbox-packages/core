<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Mail;

use Symfony\Component\Mailer\Exception\ExceptionInterface as MailerException;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransportFactory;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Exception\ExceptionInterface as MimeException;

/**
 * Sends over SMTP with symfony/mailer (decision 0269), from MAILER_DSN
 * smtp:// or smtps://, or from the settings "frootbox/core.mail".
 */
final readonly class SmtpTransport implements Transport
{
    /** Seconds to wait for the server, so a test mail does not hang the backend */
    public const int TIMEOUT = 15;

    private const array LOCAL = ['localhost', '127.0.0.1', '::1'];

    public function __construct(public TransportInterface $smtp) {}

    /**
     * @throws MailException
     */
    public static function fromDsn(string $dsn): self
    {
        try {
            $transport = new EsmtpTransportFactory()->create(Dsn::fromString($dsn));
        } catch (MailerException) {
            // The message of Symfony may contain the password.
            throw new MailException('MAILER_DSN is no valid SMTP address, e.g. smtp://user:password@smtp.example.org:587.');
        }

        return new self(self::timeout($transport));
    }

    /**
     * Port 465 is encrypted from the start, any other one starts TLS when
     * the server offers it. With a user name TLS is a must, except on the
     * same machine, so no password goes over the network in clear text.
     */
    public static function fromSettings(MailSettings $settings): self
    {
        $host = trim($settings->host);
        $transport = new EsmtpTransport($host, $settings->port);
        $username = trim($settings->username);

        if ('' !== $username) {
            $transport->setUsername($username);
            $transport->setPassword($settings->password);
            $transport->setRequireTls(!\in_array(strtolower($host), self::LOCAL, true));
        }

        return new self(self::timeout($transport));
    }

    public function send(Message $message, string $from): void
    {
        try {
            $email = new Email()
                ->from($from)
                ->to(...$message->to)
                ->subject($message->subject)
                ->text($message->text);

            if (null !== $message->replyTo) {
                $email->replyTo($message->replyTo);
            }

            $this->smtp->send($email);
        } catch (MailerException|MimeException $error) {
            throw new MailException('The SMTP server did not take the mail: ' . $error->getMessage(), 0, $error);
        }
    }

    private static function timeout(TransportInterface $transport): TransportInterface
    {
        if ($transport instanceof EsmtpTransport) {
            $stream = $transport->getStream();

            if ($stream instanceof SocketStream) {
                $stream->setTimeout(self::TIMEOUT);
            }
        }

        return $transport;
    }
}
