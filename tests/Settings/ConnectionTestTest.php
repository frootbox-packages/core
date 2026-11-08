<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Settings;

use Frootbox\Core\Action\InvalidInputException;
use Frootbox\Core\Settings\ConnectionFailed;
use Frootbox\Core\Settings\ListSettings;
use Frootbox\Core\Settings\SetSecret;
use Frootbox\Core\Settings\TestConnection;
use Frootbox\Core\Tests\Support\NotesTestCase;
use Frootbox\Database\EntityNotFoundException;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(TestConnection::class)]
#[CoversClass(ConnectionFailed::class)]
final class ConnectionTestTest extends NotesTestCase
{
    public function testOnlySettingsWithATestAreTestable(): void
    {
        $testable = [];

        foreach ($this->runner()->ask(new ListSettings()) as $settings) {
            $testable[$settings->package] = $settings->testable;
        }

        self::assertTrue($testable['acme/notes']);
        self::assertFalse($testable['frootbox/core.mail']);
    }

    public function testSaysWhatIsWrong(): void
    {
        $result = $this->runner()->run(new TestConnection('acme/notes'));

        self::assertFalse($result->ok, 'A failed test is an answer, not an error of the action.');
        self::assertSame('Store the API key first.', $result->message);
    }

    public function testSaysThatTheConnectionWorks(): void
    {
        // The secret comes first: the container keeps what it made for the rest of the request.
        $this->runner()->run(new SetSecret('acme/notes', 'apiKey', 'secret-key'));
        $result = $this->runner()->run(new TestConnection('acme/notes'));

        self::assertSame('acme/notes', $result->package);
        self::assertTrue($result->ok);
        self::assertSame('Connected with 3 notes.', $result->message);
        self::assertGreaterThanOrEqual(0.0, $result->seconds);
    }

    public function testAnUnexpectedErrorIsAnAnswerOfTheTest(): void
    {
        $this->runner()->run(new SetSecret('acme/notes', 'apiKey', 'broken'));
        $result = $this->runner()->run(new TestConnection('acme/notes'));

        self::assertFalse($result->ok);
        self::assertSame('The notes service is down.', $result->message);
    }

    public function testSettingsWithoutATestOrPackageAreRejected(): void
    {
        try {
            $this->runner()->run(new TestConnection('frootbox/core.mail'));
            self::fail('The mail settings offer no test.');
        } catch (InvalidInputException $error) {
            self::assertArrayHasKey('/package', $error->errors);
        }

        $this->expectException(EntityNotFoundException::class);
        $this->runner()->run(new TestConnection('acme/unknown'));
    }
}
