<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Auth\Oidc;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

/**
 * Talks OpenID Connect with a provider (decision 0322): discovery, the
 * address for the browser with PKCE (S256), the exchange of the code and
 * the checks of ID and logout tokens after OIDC Core 3.1.3.7 and
 * Back-Channel Logout 1.0. Nothing of the provider is kept: no access
 * token, no refresh token, no UserInfo.
 */
final class OidcClient
{
    /** Never "none", never a shared secret. */
    public const array ALGORITHMS = ['EdDSA', 'ES256', 'RS256'];

    public const string LOGOUT_EVENT = 'http://schemas.openid.net/event/backchannel-logout';

    /** Clocks of provider and website may differ this much. */
    private const int LEEWAY = 60;

    /** An ID token is used at once, a logout token within minutes. */
    private const int MAX_AGE = 300;

    private HttpClientInterface $client;

    /** @var array<string, ProviderMetadata> */
    private array $metadata = [];

    public function __construct(?HttpClientInterface $client = null)
    {
        $this->client = $client ?? HttpClient::create(['timeout' => 10, 'max_redirects' => 0]);
    }

    public function metadata(Provider $provider): ProviderMetadata
    {
        if (isset($this->metadata[$provider->issuer])) {
            return $this->metadata[$provider->issuer];
        }

        $data = $this->json('GET', rtrim($provider->issuer, '/') . '/.well-known/openid-configuration');
        $endpoints = [];

        foreach (['authorization_endpoint', 'token_endpoint', 'jwks_uri'] as $name) {
            $value = $data[$name] ?? null;

            if (!\is_string($value) || !self::isSafeUrl($value, $provider->issuer)) {
                throw OidcException::failed('no valid ' . $name);
            }

            $endpoints[$name] = $value;
        }

        if (($data['issuer'] ?? null) !== $provider->issuer) {
            throw OidcException::failed('the issuer does not match');
        }

        return $this->metadata[$provider->issuer] = new ProviderMetadata($provider->issuer, $endpoints['authorization_endpoint'], $endpoints['token_endpoint'], $endpoints['jwks_uri']);
    }

    /**
     * Where the browser goes to sign in, with prompt "none" for a silent
     * sign-in.
     */
    public function authorizationUrl(Provider $provider, SignInState $state, ?string $prompt = null): string
    {
        $endpoint = $this->metadata($provider)->authorizationEndpoint;
        $query = http_build_query(array_filter([
            'response_type' => 'code',
            'client_id' => $provider->clientId,
            'redirect_uri' => $state->redirectUri,
            'scope' => implode(' ', $provider->scopes),
            'state' => $state->state,
            'nonce' => $state->nonce,
            'code_challenge' => $state->challenge(),
            'code_challenge_method' => 'S256',
            'prompt' => $prompt,
        ], static fn(?string $value): bool => null !== $value), '', '&', \PHP_QUERY_RFC3986);

        return $endpoint . (str_contains($endpoint, '?') ? '&' : '?') . $query;
    }

    /**
     * Redeems the code and returns the checked claims of the ID token.
     *
     * @return array<string, mixed>
     */
    public function signIn(Provider $provider, string $code, SignInState $state): array
    {
        $metadata = $this->metadata($provider);
        $body = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $state->redirectUri,
            'code_verifier' => $state->verifier,
            'client_id' => $provider->clientId,
            ...$provider->authentication->parameters($provider->clientId, $metadata->tokenEndpoint),
        ];
        $answer = $this->json('POST', $metadata->tokenEndpoint, $body);
        $token = $answer['id_token'] ?? null;

        if (!\is_string($token)) {
            throw OidcException::failed('no ID token');
        }

        $claims = $this->decode($provider, $token);
        $this->checkAudience($provider, $claims);

        if (!\is_string($claims['nonce'] ?? null) || !hash_equals($state->nonce, $claims['nonce'])) {
            throw OidcException::failed('the nonce does not match');
        }

        if (!\is_int($claims['exp'] ?? null)) {
            throw OidcException::failed('the ID token does not expire');
        }

        if (!\is_string($claims['sub'] ?? null) || '' === $claims['sub']) {
            throw OidcException::failed('no subject');
        }

        return $claims;
    }

    /**
     * The checked claims of a logout token: sid or sub, or both.
     *
     * @return array<string, mixed>
     */
    public function logout(Provider $provider, string $token): array
    {
        $claims = $this->decode($provider, $token);
        $this->checkAudience($provider, $claims);
        $events = $claims['events'] ?? null;

        if (!\is_array($events) || !\array_key_exists(self::LOGOUT_EVENT, $events) || \array_key_exists('nonce', $claims)) {
            throw OidcException::failed('no logout token');
        }

        if (!\is_string($claims['jti'] ?? null) || (!\is_string($claims['sid'] ?? null) && !\is_string($claims['sub'] ?? null))) {
            throw OidcException::failed('jti and sid or sub are required');
        }

        return $claims;
    }

    /**
     * Checks signature, algorithm, issuer and times.
     *
     * @return array<string, mixed>
     */
    private function decode(Provider $provider, string $token): array
    {
        $keys = $this->keys($provider);
        $leeway = JWT::$leeway;
        JWT::$leeway = self::LEEWAY;

        try {
            $decoded = JWT::decode($token, 1 === \count($keys) && isset($keys['']) ? $keys[''] : $keys);
        } catch (Throwable $error) {
            throw OidcException::failed($error->getMessage());
        } finally {
            JWT::$leeway = $leeway;
        }

        $claims = json_decode(json_encode($decoded, \JSON_THROW_ON_ERROR), true, flags: \JSON_THROW_ON_ERROR);

        if (!\is_array($claims) || ($claims['iss'] ?? null) !== $provider->issuer) {
            throw OidcException::failed('the issuer does not match');
        }

        $issuedAt = $claims['iat'] ?? null;

        if (!\is_int($issuedAt) || $issuedAt < time() - self::MAX_AGE - self::LEEWAY) {
            throw OidcException::failed('the token is too old');
        }

        /** @var array<string, mixed> $claims */
        return $claims;
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function checkAudience(Provider $provider, array $claims): void
    {
        $audience = $claims['aud'] ?? null;
        $audience = \is_string($audience) ? [$audience] : (\is_array($audience) ? $audience : []);
        $party = $claims['azp'] ?? null;

        if (!\in_array($provider->clientId, $audience, true) || (\count($audience) > 1 && null === $party) || (null !== $party && $party !== $provider->clientId)) {
            throw OidcException::failed('the token is for another client');
        }
    }

    /**
     * The keys of the provider for the allowed algorithms, by kid.
     *
     * @return array<string, Key>
     */
    private function keys(Provider $provider): array
    {
        $data = $this->json('GET', $this->metadata($provider)->jwksUri);
        $keys = [];

        foreach (\is_array($data['keys'] ?? null) ? $data['keys'] : [] as $jwk) {
            if (!\is_array($jwk) || ('sig' !== ($jwk['use'] ?? 'sig'))) {
                continue;
            }

            $algorithm = $jwk['alg'] ?? match ([$jwk['kty'] ?? null, $jwk['crv'] ?? null]) {
                ['OKP', 'Ed25519'] => 'EdDSA',
                ['EC', 'P-256'] => 'ES256',
                ['RSA', null] => 'RS256',
                default => null,
            };

            if (!\is_string($algorithm) || !\in_array($algorithm, self::ALGORITHMS, true)) {
                continue;
            }

            try {
                $key = JWK::parseKey($jwk, $algorithm);
            } catch (Throwable) {
                continue;
            }

            if (null !== $key) {
                $keys[\is_string($jwk['kid'] ?? null) ? $jwk['kid'] : ''] = $key;
            }
        }

        return [] === $keys ? throw OidcException::failed('no keys') : $keys;
    }

    /**
     * @param array<string, string>|null $form
     *
     * @return array<mixed>
     */
    private function json(string $method, string $url, ?array $form = null): array
    {
        try {
            $response = $this->client->request($method, $url, ['headers' => ['Accept' => 'application/json'], ...null === $form ? [] : ['body' => $form]]);
            $status = $response->getStatusCode();
            $data = json_decode($response->getContent(false), true);
        } catch (ExceptionInterface $error) {
            throw OidcException::failed('not reachable (' . $error->getMessage() . ')');
        }

        if ($status >= 400) {
            $reason = \is_array($data) && \is_string($data['error_description'] ?? null) ? $data['error_description'] : (\is_array($data) && \is_string($data['error'] ?? null) ? $data['error'] : 'HTTP ' . $status);

            throw OidcException::failed($reason);
        }

        return \is_array($data) && $status < 300 ? $data : throw OidcException::failed('no JSON');
    }

    /**
     * HTTPS, or HTTP when the issuer itself is a local one on HTTP.
     */
    private static function isSafeUrl(string $url, string $issuer): bool
    {
        $scheme = parse_url($url, \PHP_URL_SCHEME);

        return 1 === preg_match('#^https?://[^/?\#]+#', $url) && ('https' === $scheme || 'http' === parse_url($issuer, \PHP_URL_SCHEME));
    }
}
