<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Mail;

use Frootbox\Core\Mail\Mailer;
use Frootbox\Core\Mail\MailException;
use Frootbox\Core\Mail\MemoryTransport;
use Frootbox\Core\Mail\Message;
use Frootbox\Core\Mail\SendMail;
use Frootbox\Core\Queue\Queue;
use Frootbox\Core\Queue\Worker;
use Frootbox\Core\Tests\Support\NotesTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(Mailer::class)]
#[CoversClass(SendMail::class)]
#[CoversClass(MemoryTransport::class)]
final class MailerTest extends NotesTestCase
{
    public function testSendsThroughTheQueue(): void
    {
        $this->get(Queue::class)->push(SendMail::of(new Message(['team@example.org', 'kein Empfänger'], "Neue\r\nAnfrage", 'Hallo', 'gast@example.org')));

        self::assertSame([], $this->transport()->sent(), 'Nothing leaves before the worker.');
        self::assertSame(1, $this->get(Worker::class)->run(5)->done);

        $sent = $this->transport()->sent();
        self::assertCount(1, $sent);
        self::assertSame(['team@example.org'], $sent[0]['message']->to, 'Invalid addresses are left out.');
        self::assertSame('Neue  Anfrage', $sent[0]['message']->subject, 'No line breaks in a header.');
        self::assertSame('gast@example.org', $sent[0]['message']->replyTo);
        self::assertSame('noreply@example.org', $sent[0]['from']);
    }

    public function testRefusesAMailWithoutRecipientOrAnInjectedReplyTo(): void
    {
        $mailer = $this->get(Mailer::class);
        $mailer->send(new Message(['team@example.org'], 'Hallo', 'Text', "gast@example.org\r\nBcc: spam@example.org"));
        self::assertNull($this->transport()->sent()[0]['message']->replyTo);

        $this->expectException(MailException::class);
        $mailer->send(new Message(['nobody'], 'Hallo', 'Text'));
    }

    protected function env(): array
    {
        return ['MAILER_DSN' => 'memory://default'];
    }

    protected function config(): array
    {
        return ['app' => ['url' => 'https://example.org']];
    }

    private function transport(): MemoryTransport
    {
        $transport = $this->get(Mailer::class)->transport();
        self::assertInstanceOf(MemoryTransport::class, $transport);

        return $transport;
    }
}
