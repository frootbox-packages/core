<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Hub;

use Frootbox\Core\Action\AccessDeniedException;
use Frootbox\Core\Action\ActionException;
use Frootbox\Core\Action\Channel;
use Frootbox\Core\Action\Context;
use Frootbox\Core\Action\InvalidInputException;
use Frootbox\Core\Action\Runner;
use Frootbox\Core\Auth\Actor;
use Frootbox\Core\Auth\Oidc\GrantAccess;
use Frootbox\Core\Auth\Oidc\RevokeAccess;
use Frootbox\Core\Auth\Oidc\RoleLimit;
use Frootbox\Core\Auth\Oidc\UserIdentities;
use Frootbox\Core\Error\Problem;
use Frootbox\Core\Schedule\Scheduler;
use Frootbox\Core\Security\StandardWebhooks;
use Frootbox\Core\User\RoleAssignments;
use Frootbox\Core\User\Users;
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
 * - tick {connection}: the hub asks a website without cron to run what is
 *   due (decision 0293). Answers 202 at once and works after the response.
 * - access {connection, actor, person, role}: a person in the hub gives or
 *   takes access to this website (decision 0322). Giving runs
 *   user.grant-access as the linked local account of the actor, with its
 *   rights, at most the role the hub gives the actor, capped by maxRole.
 *   Taking (role null) runs user.revoke-access for the hub.
 *
 * Like the REST API it runs past the password of the website.
 */
final readonly class HubCallbackHandler implements RequestHandlerInterface
{
    public const string ROUTE = '/.well-known/frootbox/{name:verify|disconnect|keys|tick|access}';

    public const string VERIFY_LABEL = 'frootbox-connect-verify-v1';

    private const int SIZE = 10000;

    public function __construct(
        private Hub $hub,
        private HubClient $client,
        private Runner $runner,
        private SeenMessages $seen,
        private ResponseFactoryInterface $responses,
        private Scheduler $scheduler,
        private HubLogin $login,
        private UserIdentities $identities,
        private Users $users,
        private RoleAssignments $assignments,
        private RoleLimit $limit,
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
            'tick' => $this->tick($connection, $data),
            'access' => $this->access($connection, $data),
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
    private function tick(HubConnection $connection, array $data): ResponseInterface
    {
        $id = $data['connection'] ?? null;

        if (!$connection->connected() || !\is_string($id) || !hash_equals((string) $connection->connectionId, $id)) {
            return $this->problem(404, 'unknown', 'There is no connection with this id.');
        }

        return $this->json(202, ['accepted' => $this->scheduler->runForHub()]);
    }

    /**
     * @param array<mixed> $data
     */
    private function access(HubConnection $connection, array $data): ResponseInterface
    {
        $id = $data['connection'] ?? null;

        if (!$connection->connected() || !\is_string($id) || !hash_equals((string) $connection->connectionId, $id)) {
            return $this->problem(404, 'unknown', 'There is no connection with this id.');
        }

        $provider = $this->login->providers()[0] ?? null;

        if (null === $provider) {
            return $this->problem(409, 'login-off', 'Signing in through the hub is off on this website.');
        }

        $person = \is_array($data['person'] ?? null) ? $data['person'] : [];
        $actor = \is_array($data['actor'] ?? null) ? $data['actor'] : [];
        $subject = $person['sub'] ?? null;
        $role = $data['role'] ?? null;

        if (!\is_string($subject) || '' === $subject || (null !== $role && !\is_string($role))) {
            return $this->problem(400, 'input', 'person.sub is required, role is a name or null.');
        }

        if (null === $role) {
            $result = $this->runner->run(new RevokeAccess($provider->key, $subject), Context::system(Channel::Hub));

            return $this->json(200, ['result' => $result]);
        }

        $email = $person['email'] ?? null;
        $name = \is_string($person['name'] ?? null) && '' !== trim($person['name']) ? trim($person['name']) : $email;

        if (!\is_string($email) || !\is_string($name) || true !== ($person['email_verified'] ?? null)) {
            return $this->problem(422, 'input', 'person needs a confirmed email.');
        }

        $identity = \is_string($actor['sub'] ?? null) ? $this->identities->findBySubject($provider->issuer, $actor['sub']) : null;
        $user = null === $identity ? null : $this->users->fetchById($identity->userId);

        if (null === $user || !$user->active) {
            return $this->problem(404, 'unknown-actor', 'The website does not know you yet: sign in there through the hub once.');
        }

        // The same rule as for signing in: never more than the hub may give.
        $allowed = $this->limit->cap(\is_string($actor['role'] ?? null) ? $actor['role'] : null, $provider->maxRole);

        if (null === $allowed) {
            return $this->problem(403, 'forbidden', 'You have no access to this website through the hub.');
        }

        if ($this->assignments->globalLevel($user->id) > $this->limit->level($allowed)) {
            return $this->problem(409, 'ranks-higher', 'Your account on the website ranks above what the hub may give.');
        }

        try {
            $granted = $this->runner->run(new GrantAccess($provider->key, $subject, $email, mb_substr($name, 0, 255), $role), new Context(Actor::user($user->id, $user->name), Channel::Hub));
        } catch (AccessDeniedException $error) {
            return $this->problem(403, 'forbidden', $error->getMessage());
        } catch (InvalidInputException $error) {
            return $this->problem(422, 'unprocessable', $error->getMessage());
        } catch (ActionException $error) {
            return $this->problem(409, 'conflict', $error->getMessage());
        }

        return $this->json(200, ['result' => $granted->result, 'user' => ['id' => $granted->user->id, 'name' => $granted->user->name]]);
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
