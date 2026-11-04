<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Mail;

use Frootbox\Core\Action\InvalidInputException;
use Frootbox\Core\Config\Config;
use Frootbox\Core\Config\Environment;
use Frootbox\Core\Mail\Mailer;
use Frootbox\Core\Mail\MailException;
use Frootbox\Core\Mail\MailSettings;
use Frootbox\Core\Mail\MailStatus;
use Frootbox\Core\Mail\Message;
use Frootbox\Core\Mail\NativeTransport;
use Frootbox\Core\Mail\SendTestMail;
use Frootbox\Core\Mail\ShowMailStatus;
use Frootbox\Core\Mail\SmtpTransport;
use Frootbox\Core\Settings\SetSecret;
use Frootbox\Core\Settings\Settings;
use Frootbox\Core\Settings\ShowSettings;
use Frootbox\Core\Settings\UpdateSettings;
use Frootbox\Core\Tests\Support\NotesTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Log\NullLogger;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/**
 * SMTP from the settings or MAILER_DSN (decision 0269), live.
 */
#[CoversClass(Mailer::class)]
#[CoversClass(SmtpTransport::class)]
#[CoversClass(MailSettings::class)]
#[CoversClass(ShowMailStatus::class)]
#[CoversClass(SendTestMail::class)]
final class SmtpTest extends NotesTestCase
{
    private const string PACKAGE = 'frootbox/core.mail';

    public function testLiveWithoutSettingsMailsGoOutWithMailOfPhp(): void
    {
        $status = $this->runner()->ask(new ShowMailStatus());

        self::assertSame('native', $status->transport);
        self::assertSame(MailStatus::STANDARD, $status->source);
        self::assertSame('noreply@www.example.org', $status->from);
        self::assertInstanceOf(NativeTransport::class, $this->get(Mailer::class)->transport());
    }

    public function testTheSettingsSendOverSmtp(): void
    {
        $details = $this->runner()->ask(new ShowSettings(self::PACKAGE));
        self::assertSame('Email', $details->title, 'A card of its own besides the one about privacy.');

        $this->runner()->run(new UpdateSettings(self::PACKAGE, ['from' => 'praxis@example.org', 'host' => 'smtp.example.org', 'port' => 465, 'username' => 'praxis@example.org']));
        $this->runner()->run(new SetSecret(self::PACKAGE, 'password', 'geheim-123'));

        $status = $this->runner()->ask(new ShowMailStatus());
        self::assertSame(['smtp', MailStatus::SETTINGS, 'praxis@example.org', 'smtp.example.org', 465, false], [$status->transport, $status->source, $status->from, $status->host, $status->port, $status->settingsIgnored]);
        self::assertStringNotContainsString('geheim', (string) json_encode($status), 'Never the password.');

        $transport = $this->get(Mailer::class)->transport();
        self::assertInstanceOf(SmtpTransport::class, $transport);
        self::assertInstanceOf(EsmtpTransport::class, $transport->smtp);
        self::assertSame('praxis@example.org', $transport->smtp->getUsername());
        self::assertSame('geheim-123', $transport->smtp->getPassword());
        self::assertTrue($transport->smtp->isTlsRequired(), 'No password in clear text over the network.');
        $local = SmtpTransport::fromSettings(new MailSettings(host: 'localhost', username: 'me'))->smtp;
        self::assertInstanceOf(EsmtpTransport::class, $local);
        self::assertFalse($local->isTlsRequired(), 'Except on the same machine.');
    }

    public function testMailerDsnGoesFirst(): void
    {
        $this->runner()->run(new UpdateSettings(self::PACKAGE, ['host' => 'smtp.example.org']));

        $status = $this->mailer(Environment::Production, 'smtps://user:geheim@mail.example.net:465')->status();
        self::assertSame(['smtp', MailStatus::ENVIRONMENT, 'mail.example.net', 465, true], [$status->transport, $status->source, $status->host, $status->port, $status->settingsIgnored]);
        self::assertInstanceOf(SmtpTransport::class, $this->mailer(Environment::Production, 'smtp://user:geheim@mail.example.net:587')->transport());

        $this->expectException(MailException::class);
        $this->expectExceptionMessage('MAILER_DSN "sendmail://…" is not supported');
        $this->mailer(Environment::Production, 'sendmail://default')->transport();
    }

    public function testInDevelopmentTheSettingsSendNothing(): void
    {
        $this->runner()->run(new UpdateSettings(self::PACKAGE, ['host' => 'smtp.example.org']));

        $status = $this->mailer(Environment::Development)->status();

        self::assertSame(['log', MailStatus::STANDARD, true], [$status->transport, $status->source, $status->settingsIgnored], 'A copy of a live database sends no mails.');
    }

    public function testComposesThePlainTextMail(): void
    {
        $smtp = new class implements TransportInterface {
            public ?Email $sent = null;

            public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
            {
                $this->sent = $message instanceof Email ? $message : null;

                return null;
            }

            public function __toString(): string
            {
                return 'test://';
            }
        };

        new SmtpTransport($smtp)->send(new Message(['team@example.org'], 'Neue Anfrage', 'Hallo Team', 'gast@example.org'), 'praxis@example.org');

        self::assertNotNull($smtp->sent);
        self::assertSame('praxis@example.org', $smtp->sent->getFrom()[0]->getAddress());
        self::assertSame('team@example.org', $smtp->sent->getTo()[0]->getAddress());
        self::assertSame('gast@example.org', $smtp->sent->getReplyTo()[0]->getAddress());
        self::assertSame('Neue Anfrage', $smtp->sent->getSubject());
        self::assertSame('Hallo Team', $smtp->sent->getTextBody());
    }

    public function testATestMailShowsTheErrorOfTheServer(): void
    {
        // Nothing listens on port 1, the connection fails at once.
        $this->runner()->run(new UpdateSettings(self::PACKAGE, ['host' => '127.0.0.1', 'port' => 1]));

        try {
            $this->runner()->run(new SendTestMail('team@example.org'));
            self::fail('The error of the server is the answer.');
        } catch (InvalidInputException $error) {
            self::assertStringContainsString('The SMTP server did not take the mail', $error->errors[''] ?? '');
        }
    }

    public function testAnInvalidDsnDoesNotShowThePassword(): void
    {
        try {
            SmtpTransport::fromDsn('smtp://user:geheim@');
            self::fail('The address is invalid.');
        } catch (MailException $error) {
            self::assertStringNotContainsString('geheim', $error->getMessage());
        }
    }

    protected function environment(): Environment
    {
        return Environment::Production;
    }

    protected function config(): array
    {
        return ['app' => ['url' => 'https://www.example.org', 'name' => 'Praxis']];
    }

    private function mailer(Environment $environment, ?string $dsn = null): Mailer
    {
        return new Mailer(new Config($environment, [], null === $dsn ? [] : ['MAILER_DSN' => $dsn]), new NullLogger(), $this->get(Settings::class));
    }
}
