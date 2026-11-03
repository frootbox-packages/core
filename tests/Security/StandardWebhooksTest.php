<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Security;

use Frootbox\Core\Security\StandardWebhooks;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(StandardWebhooks::class)]
final class StandardWebhooksTest extends TestCase
{
    // The example of the specification "Standard Webhooks".
    private const string SECRET = 'whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw';

    private const string ID = 'msg_p5jXN8AQM9LWM0D4loKWxJek';

    private const int TIME = 1614265330;

    private const string BODY = '{"test": 2432232314}';

    public function testSignsLikeTheSpecification(): void
    {
        self::assertSame('v1,g0hM9SsE+OTPJTGt/tmIKtSyZlE3uFJELVlNIOLJ1OE=', StandardWebhooks::sign(self::SECRET, self::ID, self::TIME, self::BODY));
        self::assertSame(['webhook-id' => self::ID, 'webhook-timestamp' => '1614265330', 'webhook-signature' => 'v1,g0hM9SsE+OTPJTGt/tmIKtSyZlE3uFJELVlNIOLJ1OE='], StandardWebhooks::headers(self::SECRET, self::ID, self::BODY, self::TIME));
        self::assertMatchesRegularExpression('~^whsec_[A-Za-z0-9+/]{32}$~', StandardWebhooks::secret());
        self::assertNotSame(StandardWebhooks::secret(), StandardWebhooks::secret());
    }

    public function testVerifiesSignatureAndTime(): void
    {
        $headers = StandardWebhooks::headers(self::SECRET, self::ID, self::BODY, self::TIME);

        self::assertTrue(StandardWebhooks::verify(self::SECRET, $headers, self::BODY, self::TIME + 60));
        self::assertTrue(StandardWebhooks::verify(self::SECRET, array_change_key_case($headers, \CASE_UPPER), self::BODY, self::TIME), 'Header names in any case.');
        self::assertTrue(StandardWebhooks::verify(self::SECRET, ['webhook-signature' => 'v1,old ' . $headers['webhook-signature']] + $headers, self::BODY, self::TIME), 'One of several signatures.');
        self::assertFalse(StandardWebhooks::verify(self::SECRET, $headers, self::BODY . ' ', self::TIME), 'Another body.');
        self::assertFalse(StandardWebhooks::verify('whsec_' . base64_encode('another key'), $headers, self::BODY, self::TIME), 'Another secret.');
        self::assertFalse(StandardWebhooks::verify(self::SECRET, $headers, self::BODY, self::TIME + StandardWebhooks::TOLERANCE + 1), 'Too old.');
        self::assertFalse(StandardWebhooks::verify(self::SECRET, $headers, self::BODY, self::TIME - StandardWebhooks::TOLERANCE - 1), 'From the future.');
        self::assertFalse(StandardWebhooks::verify(self::SECRET, ['webhook-timestamp' => 'soon'] + $headers, self::BODY, self::TIME));
        self::assertFalse(StandardWebhooks::verify(self::SECRET, [], self::BODY, self::TIME));
        self::assertFalse(StandardWebhooks::verify('whsec_***', $headers, self::BODY, self::TIME), 'A broken secret is no match.');
    }

    public function testRefusesABrokenSecret(): void
    {
        $this->expectException(InvalidArgumentException::class);

        StandardWebhooks::sign('whsec_***', self::ID, self::TIME, self::BODY);
    }
}
