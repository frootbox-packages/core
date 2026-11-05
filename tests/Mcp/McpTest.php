<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Mcp;

use Frootbox\Core\Action\Catalog;
use Frootbox\Core\Action\Channel;
use Frootbox\Core\Action\Context;
use Frootbox\Core\Action\CurrentContext;
use Frootbox\Core\Action\Descriptor;
use Frootbox\Core\Application;
use Frootbox\Core\Auth\AccessTokens;
use Frootbox\Core\Auth\TokenType;
use Frootbox\Core\Http\MiddlewareDispatcher;
use Frootbox\Core\Http\NotFoundHandler;
use Frootbox\Core\Mcp\McpHandler;
use Frootbox\Core\Mcp\ProtocolError;
use Frootbox\Core\Mcp\Tools;
use Frootbox\Core\Tests\Fixtures\Plugin\PublishItems;
use Frootbox\Core\Tests\Support\NotesTestCase;
use Frootbox\Core\User\CreateRole;
use Frootbox\Core\User\CreateUser;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

#[CoversClass(McpHandler::class)]
#[CoversClass(Tools::class)]
#[CoversClass(ProtocolError::class)]
#[CoversClass(Descriptor::class)]
final class McpTest extends NotesTestCase
{
    private const string VERSION = '2026-07-28';

    private int $users = 0;

    public function testAnOlderClientInitializesWithoutASession(): void
    {
        $response = $this->send(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
            'protocolVersion' => '2025-06-18',
            'capabilities' => [],
            'clientInfo' => ['name' => 'test', 'version' => '1'],
        ]], token: $this->token(['superuser']));

        $result = $this->rpcResult($response);
        self::assertSame('2025-06-18', $result['protocolVersion']);
        self::assertSame(['tools' => []], $result['capabilities']);
        self::assertSame('frootbox', $this->array($result['serverInfo'])['name']);
        self::assertIsString($result['instructions']);
        self::assertFalse($response->hasHeader('Mcp-Session-Id'));
        self::assertArrayNotHasKey('resultType', $result);

        $result = $this->rpcResult($this->send(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'initialize', 'params' => ['protocolVersion' => '2024-11-05']], token: $this->token([])));
        self::assertSame(McpHandler::LEGACY_VERSIONS[0], $result['protocolVersion'], 'An unknown version gets the newest.');
    }

    public function testListsWhatTheUserMayRun(): void
    {
        $tools = $this->tools($this->token(['superuser']));

        self::assertSame(['readOnlyHint' => false, 'destructiveHint' => false], $tools['note_create']['annotations']);
        self::assertSame('note.create', $tools['note_create']['title']);
        self::assertSame('Creates a note', $tools['note_create']['description']);
        self::assertSame('object', $this->array($tools['note_create']['inputSchema'])['type']);
        self::assertSame(['readOnlyHint' => false, 'destructiveHint' => true], $tools['note_delete']['annotations']);
        self::assertSame(['readOnlyHint' => true], $tools['note_list']['annotations']);
        self::assertArrayHasKey('acme__shop__order_list', $tools);
        self::assertArrayNotHasKey('note_subscribe', $tools, 'Actions for visitors are no tools.');
        self::assertArrayNotHasKey('account_sign-in', $tools);
        self::assertSame(array_keys($tools), array_values(array_unique(array_keys($tools))));

        $tools = $this->tools($this->token(['shop-clerk']));
        self::assertArrayHasKey('acme__shop__order_list', $tools);
        self::assertArrayHasKey('note_list', $tools, 'Public queries are for everyone.');
        self::assertArrayNotHasKey('note_create', $tools);
    }

    public function testRunsAToolOnTheChannelMcp(): void
    {
        $token = $this->token(['superuser']);
        $result = $this->call('note_create', ['title' => 'Hello', 'tags' => ['a']], $token);

        self::assertFalse($result['isError']);
        $data = $this->array($this->array($result['structuredContent'])['data']);
        self::assertSame('Hello', $data['title']);
        $content = $result['content'];
        self::assertIsList($content);
        self::assertCount(2, $content);
        self::assertSame($data, json_decode($this->string($this->array($content[0] ?? null)['text']), true));
        self::assertSame(1, $this->notes()->count());
        $entry = $this->lastEntry();
        self::assertSame(Channel::Mcp->value, $entry->channel);

        $undo = ['tool' => 'action_undo', 'arguments' => ['id' => $entry->id]];
        self::assertSame($undo, $this->array($result['structuredContent'])['undo']);
        self::assertSame('To undo this, call action_undo with {"id": ' . $entry->id . '}.', $this->array($content[1] ?? null)['text']);

        $this->call('action_undo', ['id' => $entry->id], $token);
        self::assertSame(0, $this->notes()->count(), 'Undone through MCP.');

        $result = $this->call('note_list', [], $token);
        self::assertArrayNotHasKey('undo', $this->array($result['structuredContent']), 'Queries change nothing.');
    }

    public function testErrorsTheModelCanFixAreResults(): void
    {
        $token = $this->token(['superuser']);

        $result = $this->call('note_create', ['title' => '', 'priority' => 99], $token);
        self::assertTrue($result['isError']);
        $text = $this->text($result);
        self::assertStringContainsString('/title: ', $text);
        self::assertStringContainsString('/priority: ', $text);
        self::assertSame('invalid-input', $this->array($result['structuredContent'])['code']);

        $result = $this->call('note_rename', ['id' => 999, 'title' => 'Gone'], $token);
        self::assertTrue($result['isError']);
        self::assertSame(404, $this->array($result['structuredContent'])['status']);
    }

    public function testUnknownToolsAndServerErrorsAreProtocolErrors(): void
    {
        $token = $this->token(['shop-clerk']);
        $this->rpcError($this->send($this->request('tools/call', ['name' => 'note_create', 'arguments' => ['title' => 'No']]), token: $token), 200, ProtocolError::INVALID_PARAMS);
        $this->rpcError($this->send($this->request('tools/call', ['name' => 'note_create', 'arguments' => ['No']]), token: $this->token(['superuser'])), 200, ProtocolError::INVALID_PARAMS);

        $error = $this->rpcError($this->send($this->request('tools/call', ['name' => 'note_fail', 'arguments' => ['title' => 'Boom']]), token: $this->token(['superuser'])), 500, ProtocolError::INTERNAL_ERROR);
        self::assertSame(0, $this->notes()->count());
        self::assertSame('Something broke', $error['message'], 'Development shows the message.');
    }

    public function testANewerClientSendsTheProtocolInEveryRequest(): void
    {
        $token = $this->token(['superuser']);
        $response = $this->modern('server/discover', [], $token);

        $result = $this->rpcResult($response);
        self::assertSame('complete', $result['resultType']);
        self::assertSame([self::VERSION], $result['supportedVersions']);
        self::assertSame(['tools' => []], $result['capabilities']);
        self::assertSame('frootbox', $this->array($this->array($result['_meta'])['io.modelcontextprotocol/serverInfo'])['name']);

        $result = $this->rpcResult($this->modern('tools/list', [], $token));
        self::assertSame('complete', $result['resultType']);
        self::assertNotSame([], $result['tools']);

        $result = $this->rpcResult($this->modern('tools/call', ['name' => 'note_create', 'arguments' => ['title' => 'Modern']], $token));
        self::assertSame('complete', $result['resultType']);
        self::assertFalse($result['isError']);
    }

    public function testHeadersMustMatchTheBody(): void
    {
        $token = $this->token(['superuser']);
        $body = $this->request('tools/call', ['name' => 'note_create', 'arguments' => ['title' => 'Hi']], self::VERSION);

        $this->rpcError($this->send($body, ['MCP-Protocol-Version' => self::VERSION, 'Mcp-Method' => 'tools/call'], $token), 400, ProtocolError::HEADER_MISMATCH);
        $this->rpcError($this->send($body, ['MCP-Protocol-Version' => self::VERSION, 'Mcp-Method' => 'tools/list', 'Mcp-Name' => 'note_create'], $token), 400, ProtocolError::HEADER_MISMATCH);
        $this->rpcError($this->send($body, ['Mcp-Method' => 'tools/call', 'Mcp-Name' => 'note_create'], $token), 400, ProtocolError::HEADER_MISMATCH);
        self::assertSame(0, $this->notes()->count());

        $encoded = '=?base64?' . base64_encode('note_create') . '?=';
        $result = $this->rpcResult($this->send($body, ['MCP-Protocol-Version' => self::VERSION, 'Mcp-Method' => 'tools/call', 'Mcp-Name' => $encoded], $token));
        self::assertFalse($result['isError']);
    }

    public function testVersionsAndMethodsItDoesNotKnow(): void
    {
        $token = $this->token(['superuser']);

        $body = $this->request('tools/list', [], '2099-01-01');
        $error = $this->rpcError($this->send($body, ['MCP-Protocol-Version' => '2099-01-01', 'Mcp-Method' => 'tools/list'], $token), 400, ProtocolError::UNSUPPORTED_PROTOCOL_VERSION);
        self::assertSame(['supported' => [self::VERSION], 'requested' => '2099-01-01'], $error['data']);

        $body = $this->request('tools/list', []);
        $body['params'] = ['_meta' => ['io.modelcontextprotocol/protocolVersion' => self::VERSION]];
        $this->rpcError($this->send($body, ['MCP-Protocol-Version' => self::VERSION, 'Mcp-Method' => 'tools/list'], $token), 400, ProtocolError::INVALID_PARAMS);

        $this->rpcError($this->modern('resources/list', [], $token), 404, ProtocolError::METHOD_NOT_FOUND);
        $this->rpcError($this->send($this->request('resources/list', []), token: $token), 200, ProtocolError::METHOD_NOT_FOUND);
        $this->rpcError($this->send($this->request('tools/list', []), ['MCP-Protocol-Version' => '2024-01-01'], $token), 400, ProtocolError::INVALID_REQUEST);
    }

    public function testAnEmptyResultIsAnObject(): void
    {
        $response = $this->send($this->request('ping', []), token: $this->token([]));

        self::assertSame('{"jsonrpc":"2.0","id":"r1","result":{}}', (string) $response->getBody());
    }

    public function testNotificationsGetNoAnswer(): void
    {
        $response = $this->send(['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], token: $this->token([]));

        self::assertSame(202, $response->getStatusCode());
        self::assertSame('', (string) $response->getBody());
    }

    public function testOnlyABearerTokenSignsIn(): void
    {
        $response = $this->send($this->request('tools/list', []));

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('Bearer', $response->getHeaderLine('WWW-Authenticate'));
    }

    public function testBrowsersMustComeFromTheWebsite(): void
    {
        $token = $this->token(['superuser']);

        $this->rpcError($this->send($this->request('tools/list', []), ['Origin' => 'https://evil.example'], $token), 403, ProtocolError::INVALID_REQUEST);
        $this->rpcResult($this->send($this->request('tools/list', []), ['Origin' => 'http://localhost'], $token));
    }

    public function testRejectsWhatIsNoSingleMessage(): void
    {
        $token = $this->token(['superuser']);

        $this->rpcError($this->send(raw: '{"jsonrpc":', token: $token), 400, ProtocolError::PARSE_ERROR);
        $this->rpcError($this->send([$this->request('tools/list', [])], token: $token), 400, ProtocolError::INVALID_REQUEST);
        $this->rpcError($this->send(['jsonrpc' => '1.0', 'id' => 1, 'method' => 'tools/list'], token: $token), 400, ProtocolError::INVALID_REQUEST);
        $this->rpcError($this->send(['jsonrpc' => '2.0', 'id' => null, 'method' => 'tools/list'], token: $token), 400, ProtocolError::INVALID_REQUEST);

        $response = $this->handle($this->http('GET'));
        self::assertSame(405, $response->getStatusCode());
        self::assertSame('POST', $response->getHeaderLine('Allow'));
    }

    public function testDerivesWhichActionsAPersonConfirms(): void
    {
        $catalog = $this->get(Catalog::class);
        $confirm = static fn(string $name): bool => $catalog->get($name)->confirm;

        self::assertFalse($confirm('note.create'));
        self::assertFalse($confirm('page.update-field'));
        self::assertFalse($confirm('page.remove-block'), 'Removing a block changes the draft.');
        self::assertFalse($confirm('note.list'), 'Queries never.');
        self::assertTrue($confirm('note.delete'));
        self::assertTrue($confirm('note.mail'), 'What cannot be undone.');
        self::assertTrue($confirm('page.publish'));
        self::assertTrue($confirm('page.schedule'));
        self::assertTrue($confirm('media.folder-delete'));
        self::assertTrue($confirm('user.update'));
        self::assertTrue($confirm('role.create'));
        self::assertTrue($confirm('settings.update'));
        self::assertTrue(Descriptor::of(PublishItems::class)->confirm, 'Extensions alike.');
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

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function request(string $method, array $params, ?string $version = null): array
    {
        if (null !== $version) {
            $params['_meta'] = [
                'io.modelcontextprotocol/protocolVersion' => $version,
                'io.modelcontextprotocol/clientInfo' => ['name' => 'test', 'version' => '1'],
                'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
            ];
        }

        return ['jsonrpc' => '2.0', 'id' => 'r1', 'method' => $method, 'params' => [] === $params ? new \stdClass() : $params];
    }

    /**
     * @param array<string, mixed> $params
     */
    private function modern(string $method, array $params, string $token): ResponseInterface
    {
        $headers = ['MCP-Protocol-Version' => self::VERSION, 'Mcp-Method' => $method];

        if (isset($params['name']) && \is_string($params['name'])) {
            $headers['Mcp-Name'] = $params['name'];
        }

        return $this->send($this->request($method, $params, self::VERSION), $headers, $token);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function tools(string $token): array
    {
        $tools = [];

        foreach ($this->array($this->rpcResult($this->send($this->request('tools/list', []), token: $token))['tools']) as $tool) {
            $tool = $this->array($tool);
            self::assertIsString($tool['name']);
            self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{1,128}$/', $tool['name']);
            $tools[$tool['name']] = $tool;
        }

        return $tools;
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function call(string $tool, array $arguments, string $token): array
    {
        return $this->rpcResult($this->send($this->request('tools/call', ['name' => $tool, 'arguments' => $arguments]), token: $token));
    }

    private function http(string $method): ServerRequestInterface
    {
        return new Psr17Factory()->createServerRequest($method, 'http://localhost' . McpHandler::PATH);
    }

    /**
     * @param array<mixed>|null     $json
     * @param array<string, string> $headers
     */
    private function send(?array $json = null, array $headers = [], ?string $token = null, ?string $raw = null): ResponseInterface
    {
        $request = $this->http('POST')
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Accept', 'application/json, text/event-stream')
            ->withBody(new Psr17Factory()->createStream($raw ?? json_encode($json, \JSON_THROW_ON_ERROR)));

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

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
     * @return array<string, mixed>
     */
    private function rpcResult(ResponseInterface $response): array
    {
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        $body = $this->array(json_decode((string) $response->getBody(), true, 512, \JSON_THROW_ON_ERROR));
        self::assertSame('2.0', $body['jsonrpc']);
        self::assertArrayHasKey('result', $body, (string) $response->getBody());

        return $this->array($body['result']);
    }

    /**
     * @return array<string, mixed>
     */
    private function rpcError(ResponseInterface $response, int $status, int $code): array
    {
        self::assertSame($status, $response->getStatusCode(), (string) $response->getBody());
        $body = $this->array(json_decode((string) $response->getBody(), true, 512, \JSON_THROW_ON_ERROR));
        $error = $this->array($body['error'] ?? null);
        self::assertSame($code, $error['code'], (string) $response->getBody());
        self::assertIsString($error['message']);

        return $error;
    }

    /**
     * The one text block of a tool result.
     *
     * @param array<string, mixed> $result
     */
    private function text(array $result): string
    {
        $content = $result['content'] ?? null;
        self::assertIsArray($content);
        self::assertCount(1, $content);
        $block = $this->array(reset($content));
        self::assertSame('text', $block['type']);
        self::assertIsString($block['text']);

        return $block['text'];
    }

    private function string(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function array(mixed $value): array
    {
        self::assertIsArray($value);
        $keyed = [];

        foreach ($value as $key => $item) {
            $keyed[(string) $key] = $item;
        }

        return $keyed;
    }
}
