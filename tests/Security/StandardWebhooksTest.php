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

    public function testSignsWithEd25519LikeRfc8032(): void
    {
        // Test 1 of RFC 8032, section 7.1: the seed, its public key and the signature of no content.
        $secret = StandardWebhooks::SECRET_KEY_PREFIX . base64_encode((string) hex2bin('9d61b19deffd5a60ba844af492ec2cc44449c5697b326919703bac031cae7f60'));
        $public = StandardWebhooks::PUBLIC_KEY_PREFIX . base64_encode((string) hex2bin('d75a980182b10ab7d54bfed3c964073a0ee172f3daa62325af021a68f707511a'));
        $signature = 'v1a,' . base64_encode((string) hex2bin('e5564300c360ac729086e2cc806e828a84877f1eb8e5d974d873e065224901555fb8821590a33bacc61e39701cf9b46bd25bf5f0595bbe24655141438e7a100b'));

        self::assertSame($public, StandardWebhooks::publicKey($secret));
        self::assertSame($signature, StandardWebhooks::signContent($secret, ''));
        self::assertTrue(StandardWebhooks::verifyContent($public, '', $signature));
        self::assertFalse(StandardWebhooks::verifyContent($public, 'x', $signature));
    }

    public function testVerifiesAsymmetricWebhooksWithThePublicKeyOnly(): void
    {
        $pair = StandardWebhooks::keyPair();
        $other = StandardWebhooks::keyPair();
        $headers = StandardWebhooks::headers($pair['secret'], self::ID, self::BODY, self::TIME);

        self::assertMatchesRegularExpression('~^whsk_[A-Za-z0-9+/]{43}=$~', $pair['secret']);
        self::assertMatchesRegularExpression('~^whpk_[A-Za-z0-9+/]{43}=$~', $pair['public']);
        self::assertStringStartsWith('v1a,', $headers['webhook-signature']);
        self::assertTrue(StandardWebhooks::verify($pair['public'], $headers, self::BODY, self::TIME));
        self::assertTrue(StandardWebhooks::verify([$other['public'], $pair['public']], $headers, self::BODY, self::TIME), 'One of several keys, e.g. while the key changes.');
        self::assertFalse(StandardWebhooks::verify($other['public'], $headers, self::BODY, self::TIME), 'Another key.');
        self::assertFalse(StandardWebhooks::verify($pair['public'], $headers, self::BODY . ' ', self::TIME), 'Another body.');
        self::assertFalse(StandardWebhooks::verify($pair['public'], $headers, self::BODY, self::TIME + StandardWebhooks::TOLERANCE + 1), 'Too old.');
        self::assertFalse(StandardWebhooks::verify([], $headers, self::BODY, self::TIME), 'No key, no trust.');
        self::assertFalse(StandardWebhooks::verify('whpk_***', $headers, self::BODY, self::TIME), 'A broken key is no match.');
        self::assertFalse(StandardWebhooks::verify($pair['public'], ['webhook-signature' => 'v1a,***'] + $headers, self::BODY, self::TIME), 'A broken signature is no match.');

        $symmetric = StandardWebhooks::headers(self::SECRET, self::ID, self::BODY, self::TIME);
        self::assertFalse(StandardWebhooks::verify($pair['public'], $symmetric, self::BODY, self::TIME), 'A public key never accepts v1.');

        $this->expectException(InvalidArgumentException::class);
        StandardWebhooks::publicKey('whsk_' . base64_encode('short'));
    }

    public function testRefusesABrokenSecret(): void
    {
        $this->expectException(InvalidArgumentException::class);

        StandardWebhooks::sign('whsec_***', self::ID, self::TIME, self::BODY);
    }
}
