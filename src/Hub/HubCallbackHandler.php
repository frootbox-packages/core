<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Hub;

use Frootbox\Core\Action\ActionException;
use Frootbox\Core\Action\Context;
use Frootbox\Core\Action\Runner;
use Frootbox\Core\Error\Problem;
use Frootbox\Core\Security\StandardWebhooks;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * What the hub calls on the website (decision 0284), each a POST signed
 * with a key of the hub after Standard Webhooks "v1a", each webhook-id
 * once:
 *
 * - verify {id, challenge}: proves the website holds its key. Answers
 *   only for the open request or the connection, with the signature of
 *   "frootbox-connect-verify-v1:<id>:<challenge>". It has no dots, so it
 *   never passes as a webhook.
 * - disconnect {connection}: the hub disconnected; deletes the access.
 * - keys {keys}: new keys of the hub, signed with a current one.
 *
 * Like the REST API it runs past the password of the website.
 */
final readonly class HubCallbackHandler implements RequestHandlerInterface
{
    public const string ROUTE = '/.well-known/frootbox/{name:verify|disconnect|keys}';

    public const string VERIFY_LABEL = 'frootbox-connect-verify-v1';

    private const int SIZE = 10000;

    public function __construct(
        private Hub $hub,
        private HubClient $client,
        private Runner $runner,
        private SeenMessages $seen,
        private ResponseFactoryInterface $responses,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $body = (string) $request->getBody();
        $connection = $this->hub->current();

        if (\strlen($body) > self::SIZE) {
            return $this->problem(413, 'too-large', 'The message is too large.');
        }

        if (null === $connection || [] === $connection->hubKeys) {
            return $this->problem(404, 'not-connected', 'The website is not connected.');
        }

        $headers = [];

        foreach (['webhook-id', 'webhook-timestamp', 'webhook-signature'] as $name) {
            $headers[$name] = $request->getHeaderLine($name);
        }

        if (!StandardWebhooks::verify($connection->hubKeys, $headers, $body)) {
            return $this->problem(401, 'signature', 'The signature does not belong to a key of the hub, or the message is too old.');
        }

        if (!$this->seen->first($headers['webhook-id'])) {
            return $this->problem(409, 'replay', 'This message came already.');
        }

        $data = json_decode($body, true);

        if (!\is_array($data)) {
            return $this->problem(400, 'json', 'The body is no JSON object.');
        }

        return match ($request->getAttribute('name')) {
            'verify' => $this->verify($connection, $data),
            'disconnect' => $this->disconnect($connection, $data),
            default => $this->keys($data),
        };
    }

    /**
     * @param array<mixed> $data
     */
    private function verify(HubConnection $connection, array $data): ResponseInterface
    {
        $id = $data['id'] ?? null;
        $challenge = $data['challenge'] ?? null;

        if (!\is_string($id) || !\is_string($challenge) || 1 !== preg_match('~^[A-Za-z0-9_-]{16,128}$~', $challenge)) {
            return $this->problem(400, 'input', 'id and challenge are required, the challenge 16 to 128 characters of base64url.');
        }

        $known = ($connection->pending() && hash_equals((string) $connection->requestId, $id))
            || ($connection->connected() && hash_equals((string) $connection->connectionId, $id));

        if (!$known) {
            return $this->problem(404, 'unknown', 'There is no open request or connection with this id.');
        }

        try {
            $signature = StandardWebhooks::signContent($this->client->secretKey($connection), self::VERIFY_LABEL . ':' . $id . ':' . $challenge);
        } catch (HubException $error) {
            return $this->problem(503, 'key', $error->getMessage());
        }

        return $this->json(200, ['signature' => $signature]);
    }

    /**
     * @param array<mixed> $data
     */
    private function disconnect(HubConnection $connection, array $data): ResponseInterface
    {
        $id = $data['connection'] ?? null;

        if (!$connection->connected() || !\is_string($id) || !hash_equals((string) $connection->connectionId, $id)) {
            return $this->problem(404, 'unknown', 'There is no connection with this id.');
        }

        $this->runner->run(new DisconnectHub(notify: false), Context::system());

        return $this->responses->createResponse(204)->withHeader('Cache-Control', 'no-store');
    }

    /**
     * @param array<mixed> $data
     */
    private function keys(array $data): ResponseInterface
    {
        $keys = $data['keys'] ?? null;
        $keys = \is_array($keys) ? array_map(static fn(mixed $entry): mixed => \is_array($entry) ? ($entry['key'] ?? null) : $entry, $keys) : null;

        if (null === $keys || $keys !== array_filter($keys, \is_string(...))) {
            return $this->problem(400, 'input', 'keys must be a list of public keys.');
        }

        try {
            $this->runner->run(new UpdateHubKeys(array_values($keys)), Context::system());
        } catch (ActionException $error) {
            return $this->problem(422, 'unprocessable', $error->getMessage());
        }

        return $this->responses->createResponse(204)->withHeader('Cache-Control', 'no-store');
    }

    private function problem(int $status, string $code, string $detail): ResponseInterface
    {
        return $this->json($status, new Problem($status, $code, $detail)->toArray(), 'application/problem+json');
    }

    /**
     * @param array<mixed> $data
     */
    private function json(int $status, array $data, string $type = 'application/json'): ResponseInterface
    {
        $response = $this->responses->createResponse($status)
            ->withHeader('Content-Type', $type)
            ->withHeader('Cache-Control', 'no-store');
        $response->getBody()->write(json_encode($data, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));

        return $response;
    }
}
