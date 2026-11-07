<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Hub;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Frootbox\Core\Auth\Oidc\OidcClient;
use Frootbox\Core\Auth\Oidc\PrivateKeyJwt;
use PHPUnit\Framework\Assert;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The hub as a provider of OpenID Connect as frootbox.de 0038 describes
 * it, for tests: discovery, JWKS with an Ed25519 key, a token endpoint
 * that checks private_key_jwt with the key of the website and PKCE.
 */
final class FakeOidcProvider
{
    public const string CLIENT = 'con_0123456789';

    /** @var array{public: string, secret: string} raw keys of libsodium */
    public array $keys;

    /** @var array<string, array{challenge: string, redirect: string, claims: array<string, mixed>}> */
    public array $codes = [];

    /** @var array<string, mixed> What the next sign-in says about the person */
    public array $person = ['sub' => 'usr_anna', 'email' => 'anna@example.org', 'email_verified' => true, 'name' => 'Anna Hub', 'sid' => 'ses_1'];

    public ?string $role = 'admin';

    /** Claims to change in the next ID token, e.g. another audience. */
    /** @var array<string, mixed> */
    public array $tamper = [];

    public function __construct(private readonly FakeHub $hub)
    {
        $pair = sodium_crypto_sign_keypair();
        $this->keys = ['public' => sodium_crypto_sign_publickey($pair), 'secret' => sodium_crypto_sign_secretkey($pair)];
    }

    public function client(): OidcClient
    {
        return new OidcClient(new MockHttpClient($this->answer(...), FakeHub::URL));
    }

    /**
     * What the hub does when the browser comes to its authorization
     * endpoint: the person is signed in there, the code goes back.
     *
     * @return array{code: string, state: string}
     */
    public function authorize(string $url): array
    {
        parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
        Assert::assertSame(FakeHub::URL . '/oidc/authorize', strtok($url, '?'));
        Assert::assertSame('code', $query['response_type'] ?? null);
        Assert::assertSame(self::CLIENT, $query['client_id'] ?? null);
        Assert::assertSame('S256', $query['code_challenge_method'] ?? null);
        Assert::assertIsString($query['code_challenge'] ?? null);
        Assert::assertIsString($query['nonce'] ?? null);
        Assert::assertIsString($query['state'] ?? null);
        Assert::assertIsString($query['redirect_uri'] ?? null);

        $code = 'code_' . bin2hex(random_bytes(8));
        $this->codes[$code] = [
            'challenge' => $query['code_challenge'],
            'redirect' => $query['redirect_uri'],
            'claims' => [
                ...$this->person,
                'nonce' => $query['nonce'],
                'hub_access' => ['role' => $this->role, 'organization' => ['id' => 'org_1', 'name' => 'Herr und Frau Pixel']],
            ],
        ];

        return ['code' => $code, 'state' => $query['state']];
    }

    /**
     * @param array<string, mixed> $claims
     */
    public function token(array $claims, ?string $secret = null): string
    {
        return JWT::encode($claims, base64_encode($secret ?? $this->keys['secret']), 'EdDSA', 'k1');
    }

    /**
     * A logout token after Back-Channel Logout 1.0.
     *
     * @param array<string, mixed> $claims
     */
    public function logoutToken(array $claims = [], ?string $secret = null): string
    {
        return $this->token([
            'iss' => FakeHub::URL,
            'aud' => self::CLIENT,
            'iat' => time(),
            'jti' => bin2hex(random_bytes(8)),
            'events' => [OidcClient::LOGOUT_EVENT => new \stdClass()],
            ...$claims,
        ], $secret);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function answer(string $method, string $url, array $options): MockResponse
    {
        $path = (string) parse_url($url, \PHP_URL_PATH);

        return match ($path) {
            '/.well-known/openid-configuration' => self::json([
                'issuer' => FakeHub::URL,
                'authorization_endpoint' => FakeHub::URL . '/oidc/authorize',
                'token_endpoint' => FakeHub::URL . '/oidc/token',
                'jwks_uri' => FakeHub::URL . '/oidc/jwks',
            ]),
            '/oidc/jwks' => self::json(['keys' => [['kty' => 'OKP', 'crv' => 'Ed25519', 'kid' => 'k1', 'use' => 'sig', 'alg' => 'EdDSA', 'x' => rtrim(strtr(base64_encode($this->keys['public']), '+/', '-_'), '=')]]]),
            '/oidc/token' => $this->exchange(\is_string($options['body'] ?? null) ? $options['body'] : ''),
            default => new MockResponse('', ['http_code' => 404]),
        };
    }

    private function exchange(string $body): MockResponse
    {
        parse_str($body, $form);
        Assert::assertSame(PrivateKeyJwt::TYPE, $form['client_assertion_type'] ?? null);
        Assert::assertIsString($form['client_assertion'] ?? null);
        Assert::assertNotNull($this->hub->siteKey);

        // private_key_jwt: signed with the key the website told at connecting.
        $public = base64_encode((string) base64_decode(substr($this->hub->siteKey, 5), true));
        $assertion = (array) JWT::decode($form['client_assertion'], new Key($public, 'EdDSA'));
        Assert::assertSame(self::CLIENT, $assertion['iss'] ?? null);
        Assert::assertSame(self::CLIENT, $assertion['sub'] ?? null);
        Assert::assertSame(FakeHub::URL . '/oidc/token', $assertion['aud'] ?? null);

        $code = \is_string($form['code'] ?? null) ? $form['code'] : '';
        $stored = $this->codes[$code] ?? null;
        unset($this->codes[$code]);

        if (null === $stored || !\is_string($form['code_verifier'] ?? null) || $stored['redirect'] !== ($form['redirect_uri'] ?? null)) {
            return self::json(['error' => 'invalid_grant'], 400);
        }

        $challenge = rtrim(strtr(base64_encode(hash('sha256', $form['code_verifier'], true)), '+/', '-_'), '=');

        if (!hash_equals($stored['challenge'], $challenge)) {
            return self::json(['error' => 'invalid_grant', 'error_description' => 'PKCE'], 400);
        }

        $now = time();

        return self::json(['id_token' => $this->token([
            'iss' => FakeHub::URL,
            'aud' => self::CLIENT,
            'azp' => self::CLIENT,
            'iat' => $now,
            'exp' => $now + 300,
            'auth_time' => $now,
            ...$stored['claims'],
            ...$this->tamper,
        ]), 'token_type' => 'Bearer']);
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function json(array $data, int $status = 200): MockResponse
    {
        return new MockResponse(json_encode($data, \JSON_THROW_ON_ERROR), ['http_code' => $status, 'response_headers' => ['Content-Type' => 'application/json']]);
    }
}
