<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Auth;

use DateTimeImmutable;
use Frootbox\Core\Account\SignIn;
use Frootbox\Core\Action\Bookkeeping;
use Frootbox\Core\Action\Context;
use Frootbox\Core\Auth\AccessTokens;
use Frootbox\Core\Auth\AuthenticationException;
use Frootbox\Core\Auth\SignInThrottle;
use Frootbox\Core\Auth\TooManyAttemptsException;
use Frootbox\Core\Error\Problem;
use Frootbox\Core\Tests\Support\NotesTestCase;
use Frootbox\Core\User\CreateUser;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(SignInThrottle::class)]
#[CoversClass(TooManyAttemptsException::class)]
#[CoversClass(Bookkeeping::class)]
#[CoversClass(SignIn::class)]
#[CoversClass(AccessTokens::class)]
final class SignInThrottleTest extends NotesTestCase
{
    private const string PASSWORD = 'correct horse battery';

    public function testFailuresCountDespiteTheRollback(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            $this->assertRefused('wrong', AuthenticationException::class);
        }

        // Now even the right password has to wait.
        $error = $this->assertRefused(self::PASSWORD, TooManyAttemptsException::class);
        self::assertInstanceOf(TooManyAttemptsException::class, $error);
        self::assertGreaterThan(50, $error->retryAfter);
        self::assertLessThanOrEqual(60, $error->retryAfter);
        self::assertSame('Too many failed attempts. Try again in a minute.', $error->getMessage());

        $problem = Problem::from($error, showDetails: false);
        self::assertSame(429, $problem->status);
        self::assertSame((string) $error->retryAfter, $problem->headers['Retry-After']);

        self::assertSame(10, $this->rows(), 'One row for the email and one for the address per failure.');
        self::assertStringNotContainsString('jane', (string) json_encode($this->connection()->fetchAllAssociative('SELECT * FROM sign_in_failures')));
    }

    public function testTheWaitGrowsAndASuccessClearsTheAccount(): void
    {
        $throttle = $this->get(SignInThrottle::class);
        $start = new DateTimeImmutable('2026-11-01 10:00:00');

        for ($i = 0; $i < 7; ++$i) {
            $throttle->fail('Jane@Example.com', null, $start->modify("+{$i} seconds"));
        }

        $last = $start->modify('+6 seconds');
        $this->assertWait(240, $throttle, $last, '7 failures: 4 minutes.');
        $this->assertWait(0, $throttle, $last->modify('+241 seconds'));

        for ($i = 0; $i < 10; ++$i) {
            $throttle->fail('jane@example.com', null, $last->modify("+{$i} seconds"));
        }

        $this->assertWait(900, $throttle, $last->modify('+9 seconds'), 'At most 15 minutes.');
        $this->assertWait(0, $throttle, $last->modify('+20 minutes'), 'Failures older than 15 minutes do not count.');

        $throttle->clear('JANE@example.com');
        $this->assertWait(0, $throttle, $last->modify('+10 seconds'));
    }

    public function testThrottlesAnAddressAcrossAccounts(): void
    {
        $throttle = $this->get(SignInThrottle::class);
        $now = new DateTimeImmutable('2026-11-01 10:00:00');

        for ($i = 0; $i < 30; ++$i) {
            $throttle->fail("user{$i}@example.com", '203.0.113.7', $now);
        }

        try {
            $throttle->check('someone@example.com', '203.0.113.7', $now->modify('+1 minute'));
            self::fail('The address was not throttled.');
        } catch (TooManyAttemptsException $error) {
            self::assertSame(840, $error->retryAfter);
            self::assertSame('Too many failed attempts. Try again in 14 minutes.', $error->getMessage());
        }

        $throttle->check('someone@example.com', '198.51.100.1', $now->modify('+1 minute'));
        $throttle->check('someone@example.com', null, $now->modify('+1 minute'));

        // A day later the rows are gone with the next failure.
        $throttle->fail('other@example.com', null, $now->modify('+1 day +1 second'));
        self::assertSame(1, $this->rows());
    }

    public function testStaySignedInForDaysOrHours(): void
    {
        $short = $this->runner()->run(new SignIn('jane@example.com', self::PASSWORD), Context::guest());
        $long = $this->runner()->run(new SignIn('jane@example.com', self::PASSWORD, remember: true), Context::guest());

        self::assertFalse($short->session->remember);
        self::assertTrue($long->session->remember);
        self::assertEqualsWithDelta(new DateTimeImmutable('+12 hours')->getTimestamp(), $short->session->expiresAt?->getTimestamp(), 5);
        self::assertEqualsWithDelta(new DateTimeImmutable('+14 days')->getTimestamp(), $long->session->expiresAt?->getTimestamp(), 5);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->runner()->run(new CreateUser('jane@example.com', 'Jane', self::PASSWORD), Context::system());
    }

    /**
     * @param class-string<\Throwable> $expected
     */
    private function assertRefused(string $password, string $expected): \Throwable
    {
        try {
            $this->runner()->run(new SignIn('jane@example.com', $password), Context::guest(address: '203.0.113.7'));
        } catch (\Throwable $error) {
            self::assertInstanceOf($expected, $error);

            return $error;
        }

        self::fail('Signed in.');
    }

    private function assertWait(int $seconds, SignInThrottle $throttle, DateTimeImmutable $now, string $message = ''): void
    {
        try {
            $throttle->check('jane@example.com', null, $now);
            self::assertSame(0, $seconds, $message);
        } catch (TooManyAttemptsException $error) {
            self::assertSame($seconds, $error->retryAfter, $message);
        }
    }

    private function rows(): int
    {
        $count = $this->connection()->fetchOne('SELECT COUNT(*) FROM sign_in_failures');

        return is_numeric($count) ? (int) $count : -1;
    }
}
