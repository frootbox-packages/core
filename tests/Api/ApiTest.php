<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Api;

use Frootbox\Core\Action\Channel;
use Frootbox\Core\Action\Context;
use Frootbox\Core\Action\CurrentContext;
use Frootbox\Core\Api\ApiHandler;
use Frootbox\Core\Api\JsonResponder;
use Frootbox\Core\Api\OpenApi;
use Frootbox\Core\Api\OpenApiHandler;
use Frootbox\Core\Api\RequestInput;
use Frootbox\Core\Application;
use Frootbox\Core\Auth\AccessTokens;
use Frootbox\Core\Auth\AuthenticationMiddleware;
use Frootbox\Core\Auth\TokenType;
use Frootbox\Core\Error\ErrorMiddleware;
use Frootbox\Core\Error\Problem;
use Frootbox\Core\Http\MiddlewareDispatcher;
use Frootbox\Core\Http\NotFoundHandler;
use Frootbox\Core\Tests\Support\NotesTestCase;
use Frootbox\Core\User\CreateRole;
use Frootbox\Core\User\CreateUser;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

#[CoversClass(ApiHandler::class)]
#[CoversClass(RequestInput::class)]
#[CoversClass(JsonResponder::class)]
#[CoversClass(OpenApi::class)]
#[CoversClass(OpenApiHandler::class)]
#[CoversClass(Problem::class)]
#[CoversClass(ErrorMiddleware::class)]
final class ApiTest extends NotesTestCase
{
    private int $users = 0;

    public function testRunsAnActionWithJson(): void
    {
        $response = $this->send('POST', '/api/v1/note.create', json: ['title' => 'Hello', 'tags' => ['a', 'b']], token: $this->token(['superuser']));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        $data = $this->data($response);
        self::assertSame('Hello', $data['title']);
        self::assertSame(['a', 'b'], $data['tags']);
        self::assertSame(1, $this->notes()->count());
        self::assertSame(Channel::Api->value, $this->lastEntry()->channel);
        self::assertSame($this->lastEntry()->id, $this->json($response)['entry'] ?? null, 'The id for action.undo, as in the backend.');
    }

    public function testRunsAQueryWithValuesFromTheQueryString(): void
    {
        $token = $this->token(['superuser']);
        $id = $this->userId('john@example.com');

        $response = $this->send('GET', '/api/v1/user.show?id=' . $id, token: $token);

        self::assertSame(200, $response->getStatusCode());
        self::assertArrayNotHasKey('entry', $this->json($response), 'A query changes nothing.');
        $user = $this->data($response)['user'] ?? null;
        self::assertIsArray($user);
        self::assertSame('john@example.com', $user['email']);
    }

    public function testConvertsFormValuesAlongTheSchema(): void
    {
        $response = $this->send('POST', '/api/v1/note.create', form: 'title=Form&priority=3&tags[]=x', token: $this->token(['superuser']));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(3, $this->data($response)['priority']);
        self::assertSame(['x'], $this->data($response)['tags']);
    }

    public function testExtensionsAreReachableWithTheirPackageName(): void
    {
        $this->problem($this->send('GET', '/api/v1/acme/shop/order.list?limit=1', token: $this->token([])), 403, 'access-denied');

        $response = $this->send('GET', '/api/v1/acme/shop/order.list?limit=1', token: $this->token(['shop-clerk']));

        self::assertSame([['id' => 1, 'total' => 9.5]], $this->data($response));

        $paths = $this->json($this->send('GET', '/api/v1/openapi.json', token: $this->token(['shop-clerk'])))['paths'] ?? null;
        self::assertIsArray($paths);
        self::assertSame(['acme/shop'], $this->operation($paths, '/acme/shop/order.list', 'get')['tags']);
    }

    public function testAGuestMayRunPublicQueries(): void
    {
        $this->runner()->call('note.create', ['title' => 'Public'], Context::system());

        $response = $this->send('GET', '/api/v1/note.list?status=draft');

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, $this->data($response));
    }

    public function testAGuestIsAskedToSignIn(): void
    {
        $response = $this->send('POST', '/api/v1/note.create', json: ['title' => 'Hello']);

        $problem = $this->problem($response, 401, 'unauthorized');
        self::assertSame('Bearer', $response->getHeaderLine('WWW-Authenticate'));
        self::assertIsString($problem['detail']);
        self::assertStringContainsString('note.create', $problem['detail']);
        self::assertSame(0, $this->notes()->count());
    }

    public function testAUserWithoutThePermissionIsForbidden(): void
    {
        $response = $this->send('POST', '/api/v1/note.create', json: ['title' => 'Hello'], token: $this->token([]));

        $this->problem($response, 403, 'access-denied');
        self::assertSame('', $response->getHeaderLine('WWW-Authenticate'));
    }

    public function testInvalidInputNamesTheFields(): void
    {
        $response = $this->send('POST', '/api/v1/note.create', json: ['title' => '', 'priority' => 99], token: $this->token(['superuser']));

        $problem = $this->problem($response, 422, 'invalid-input');
        $pointers = array_column(\is_array($problem['errors'] ?? null) ? $problem['errors'] : [], 'pointer');
        self::assertContains('/title', $pointers);
        self::assertContains('/priority', $pointers);
    }

    public function testAnUnknownNameIsNotFound(): void
    {
        $this->problem($this->send('GET', '/api/v1/note.unknown'), 404, 'not-found');
    }

    public function testActionsArePostAndQueriesGet(): void
    {
        $response = $this->send('GET', '/api/v1/note.create', token: $this->token(['superuser']));
        $this->problem($response, 405, 'method-not-allowed');
        self::assertSame('POST', $response->getHeaderLine('Allow'));

        $response = $this->send('POST', '/api/v1/note.list', json: []);
        $this->problem($response, 405, 'method-not-allowed');
        self::assertSame('GET', $response->getHeaderLine('Allow'));
    }

    public function testRejectsBodiesItCannotRead(): void
    {
        $token = $this->token(['superuser']);

        $this->problem($this->send('POST', '/api/v1/note.create', raw: '{"title":', token: $token), 400, 'bad-request');
        $this->problem($this->send('POST', '/api/v1/note.create', raw: '["Hello"]', token: $token), 400, 'bad-request');
        $this->problem($this->send('POST', '/api/v1/note.create', raw: 'Hello', type: 'text/plain', token: $token), 415, 'unsupported-media-type');
    }

    public function testASessionCookieDoesNotSignIn(): void
    {
        $user = $this->runner()->run(new CreateUser('jane@example.com', 'Jane', roles: ['superuser']), Context::system())->user;
        $session = $this->get(AccessTokens::class)->issue($user->id, TokenType::Session)->token;

        $request = $this->request('POST', '/api/v1/note.create', json: ['title' => 'Hello'])
            ->withCookieParams([AuthenticationMiddleware::COOKIE => $session]);

        $this->problem($this->handle($request), 401, 'unauthorized');
    }

    public function testAWrongTokenIsAnsweredAsJson(): void
    {
        $response = $this->send('GET', '/api/v1/note.list', token: 'fbx_wrong');

        $this->problem($response, 401, 'unauthorized');
        self::assertSame('Bearer', $response->getHeaderLine('WWW-Authenticate'));
    }

    public function testOpenApiShowsWhatTheCallerMayRun(): void
    {
        $guest = $this->json($this->send('GET', '/api/v1/openapi.json'));
        self::assertSame(OpenApi::VERSION, $guest['openapi']);
        $paths = \is_array($guest['paths']) ? $guest['paths'] : [];
        self::assertArrayHasKey('/note.list', $paths);
        self::assertArrayHasKey('/account.sign-in', $paths);
        self::assertArrayNotHasKey('/note.create', $paths);

        $admin = $this->json($this->send('GET', '/api/v1/openapi.json', token: $this->token(['superuser'])));
        $paths = \is_array($admin['paths']) ? $admin['paths'] : [];
        $create = $this->operation($paths, '/note.create', 'post');
        self::assertSame('note.create', $create['operationId']);
        self::assertSame([['bearer' => []]], $create['security']);
        self::assertIsArray($create['requestBody']);
        self::assertTrue($create['requestBody']['required']);
        $answer = $create['responses'] ?? null;
        self::assertIsArray($answer);
        self::assertStringContainsString('"entry":{"type":"integer"', (string) json_encode($answer['200'] ?? null), 'The id for action.undo.');

        $show = $this->operation($paths, '/user.show', 'get');
        self::assertSame([['name' => 'id', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'integer']]], $show['parameters']);
    }

    public function testPagesStillGetHtmlErrors(): void
    {
        $response = $this->send('GET', '/nowhere');

        self::assertSame(404, $response->getStatusCode());
        self::assertStringStartsWith('text/html', $response->getHeaderLine('Content-Type'));
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->runner()->run(new CreateRole('shop-clerk', 'Shop clerk', ['acme/*']), Context::system());
        $this->get(CurrentContext::class)->set(Context::guest());
    }

    /**
     * @param list<string> $roles
     */
    private function token(array $roles): string
    {
        $user = $this->runner()->run(new CreateUser('user' . ++$this->users . '@example.com', 'User', roles: $roles), Context::system())->user;

        return $this->get(AccessTokens::class)->issue($user->id, TokenType::Api)->token;
    }

    private function userId(string $email): int
    {
        return $this->runner()->run(new CreateUser($email, 'John'), Context::system())->user->id;
    }

    /**
     * @param array<mixed>|null $json
     */
    private function request(string $method, string $path, ?array $json = null, ?string $form = null, ?string $raw = null, string $type = 'application/json'): ServerRequestInterface
    {
        $factory = new Psr17Factory();
        $request = $factory->createServerRequest($method, 'http://localhost' . $path);
        parse_str((string) parse_url($path, \PHP_URL_QUERY), $query);
        $request = $request->withQueryParams($query);

        if (null !== $json) {
            $request = $request->withHeader('Content-Type', 'application/json')
                ->withBody($factory->createStream(json_encode([] === $json ? new \stdClass() : $json, \JSON_THROW_ON_ERROR)));
        } elseif (null !== $form) {
            $request = $request->withHeader('Content-Type', 'application/x-www-form-urlencoded')->withBody($factory->createStream($form));
        } elseif (null !== $raw) {
            $request = $request->withHeader('Content-Type', $type)->withBody($factory->createStream($raw));
        }

        return $request;
    }

    /**
     * @param array<mixed>|null $json
     */
    private function send(string $method, string $path, ?array $json = null, ?string $form = null, ?string $raw = null, string $type = 'application/json', ?string $token = null): ResponseInterface
    {
        $request = $this->request($method, $path, $json, $form, $raw, $type);

        if (null !== $token) {
            $request = $request->withHeader('Authorization', 'Bearer ' . $token);
        }

        return $this->handle($request);
    }

    private function handle(ServerRequestInterface $request): ResponseInterface
    {
        $middleware = array_map($this->get(...), Application::MIDDLEWARE);

        return new MiddlewareDispatcher($middleware, $this->get(NotFoundHandler::class))->handle($request);
    }

    /**
     * @return array<mixed>
     */
    private function json(ResponseInterface $response): array
    {
        $json = json_decode((string) $response->getBody(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($json);

        return $json;
    }

    /**
     * @return array<mixed>
     */
    private function data(ResponseInterface $response): array
    {
        $data = $this->json($response)['data'] ?? null;
        self::assertIsArray($data, (string) $response->getBody());

        return $data;
    }

    /**
     * @param array<mixed> $paths
     *
     * @return array<mixed>
     */
    private function operation(array $paths, string $path, string $method): array
    {
        $item = $paths[$path] ?? null;
        self::assertIsArray($item, $path . ' is missing.');
        $operation = $item[$method] ?? null;
        self::assertIsArray($operation);

        return $operation;
    }

    /**
     * @return array<mixed>
     */
    private function problem(ResponseInterface $response, int $status, string $code): array
    {
        self::assertSame($status, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));
        $problem = $this->json($response);
        self::assertSame($status, $problem['status']);
        self::assertSame($code, $problem['code']);

        return $problem;
    }
}
