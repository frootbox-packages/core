<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Mcp;

use Frootbox\Core\Action\Channel;
use Frootbox\Core\Action\Context;
use Frootbox\Core\Action\CurrentContext;
use Frootbox\Core\Action\Journal;
use Frootbox\Core\Action\Normalizer;
use Frootbox\Core\Action\Runner;
use Frootbox\Core\Api\ApiHandler;
use Frootbox\Core\Config\Environment;
use Frootbox\Core\Error\Problem;
use Frootbox\Core\Frootbox;
use Frootbox\Core\Http\Exception\HttpException;
use JsonException;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use stdClass;
use Throwable;

/**
 * The MCP endpoint (decision 0289): every action and query the user may
 * run as a tool, for Claude, ChatGPT and other programs.
 *
 * Streamable HTTP without sessions and streams: each POST carries one
 * JSON-RPC message and gets one JSON answer. It speaks the protocol
 * 2026-07-28, where every request brings its version in _meta, and the
 * versions before it, which start with "initialize"; that is answered
 * without a session. Only a Bearer token signs in, like the REST API.
 * Tools run through the Runner on the channel "mcp", with permissions,
 * drafts, the action log and undo.
 */
final readonly class McpHandler implements RequestHandlerInterface
{
    public const string PATH = ApiHandler::BASE . '/mcp';

    /** Versions with the protocol fields in _meta of every request. */
    public const array VERSIONS = ['2026-07-28'];

    /** Versions that start with "initialize", newest first. */
    public const array LEGACY_VERSIONS = ['2025-11-25', '2025-06-18', '2025-03-26'];

    private const string VERSION_KEY = 'io.modelcontextprotocol/protocolVersion';

    private const string CAPABILITIES_KEY = 'io.modelcontextprotocol/clientCapabilities';

    private const string INSTRUCTIONS = 'The tools manage a website made with Frootbox, with the permissions of the signed-in person. '
        . 'They are its actions and queries: "page_create" is page.create, "frootbox__news__article_create" is frootbox/news/article.create. '
        . 'Changes land in drafts and can be undone with action_undo and the id the result names; visitors only see them once they are published. '
        . 'Ask the person before calling a tool that is not read-only and is marked as destructive, such as publishing or deleting.';

    public function __construct(
        private Tools $tools,
        private Runner $runner,
        private Journal $journal,
        private CurrentContext $context,
        private Environment $environment,
        private ResponseFactoryInterface $responses,
        private StreamFactoryInterface $streams,
        private LoggerInterface $logger,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (!self::allowsOrigin($request)) {
            return $this->error(null, new ProtocolError(ProtocolError::INVALID_REQUEST, 'Requests from this origin are not allowed.', 403));
        }

        $context = $this->context->get();

        if (Channel::Api !== $context->channel) {
            throw new HttpException(401, 'Sign in with "Authorization: Bearer" and an access token of your account.', ['WWW-Authenticate' => 'Bearer']);
        }

        $id = null;

        try {
            $message = self::message($request);

            if (!\array_key_exists('id', $message) || !\array_key_exists('method', $message)) {
                // A notification, or an answer to a request this server never sends.
                return $this->responses->createResponse(202)->withHeader('Cache-Control', 'no-store');
            }

            $id = \is_string($message['id']) || \is_int($message['id']) ? $message['id'] : throw new ProtocolError(ProtocolError::INVALID_REQUEST, 'The id must be a string or an integer.');

            $method = \is_string($message['method']) ? $message['method'] : throw new ProtocolError(ProtocolError::INVALID_REQUEST, 'The method must be a string.');
            $params = self::object($message['params'] ?? [], 'params', 400);
            $mcp = new Context($context->actor, Channel::Mcp, $context->tokenId, $context->address);
            $meta = self::object($params['_meta'] ?? [], '_meta', 400);

            $result = match (true) {
                'initialize' === $method => $this->initialize($params),
                \array_key_exists(self::VERSION_KEY, $meta) => $this->modern($request, $method, $params, $meta, $mcp),
                default => $this->legacy($request, $method, $params, $mcp),
            };

            // An empty result such as the one of ping is still an object.
            return $this->json(['jsonrpc' => '2.0', 'id' => $id, 'result' => [] === $result ? new stdClass() : $result]);
        } catch (ProtocolError $error) {
            return $this->error($id, $error);
        }
    }

    /**
     * A request after 2026-07-28: version, method and tool name come in
     * _meta and again as headers, which must match.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $meta
     *
     * @return array<string, mixed>
     */
    private function modern(ServerRequestInterface $request, string $method, array $params, array $meta, Context $context): array
    {
        $version = $meta[self::VERSION_KEY];

        if (!\is_string($version) || !\in_array($version, self::VERSIONS, true)) {
            throw new ProtocolError(ProtocolError::UNSUPPORTED_PROTOCOL_VERSION, 'This protocol version is not supported.', data: [
                'supported' => self::VERSIONS,
                'requested' => $version,
            ]);
        }

        if (!\is_array($meta[self::CAPABILITIES_KEY] ?? null)) {
            throw ProtocolError::invalidParams(\sprintf('The request needs "%s" in _meta.', self::CAPABILITIES_KEY), 400);
        }

        if ($request->getHeaderLine('MCP-Protocol-Version') !== $version) {
            throw ProtocolError::headerMismatch('MCP-Protocol-Version', $version);
        }

        if ($request->getHeaderLine('Mcp-Method') !== $method) {
            throw ProtocolError::headerMismatch('Mcp-Method', $method);
        }

        if ('tools/call' === $method) {
            $name = self::toolName($params);

            if (self::decodeHeader($request->getHeaderLine('Mcp-Name')) !== $name) {
                throw ProtocolError::headerMismatch('Mcp-Name', $name);
            }
        }

        $result = match ($method) {
            'server/discover' => [
                'supportedVersions' => self::VERSIONS,
                'capabilities' => self::capabilities(),
                'instructions' => self::INSTRUCTIONS,
            ],
            'tools/list' => $this->list($context),
            'tools/call' => $this->call($params, $context),
            'ping' => [],
            default => throw new ProtocolError(ProtocolError::METHOD_NOT_FOUND, \sprintf('The method "%s" is not supported.', $method), 404),
        };

        return ['resultType' => 'complete', ...$result, '_meta' => ['io.modelcontextprotocol/serverInfo' => self::serverInfo()]];
    }

    /**
     * A request of a version before 2026-07-28, after "initialize".
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function legacy(ServerRequestInterface $request, string $method, array $params, Context $context): array
    {
        // Clients of 2025-03-26 do not send the header yet.
        $version = $request->getHeaderLine('MCP-Protocol-Version');

        if (\in_array($version, self::VERSIONS, true)) {
            throw ProtocolError::invalidParams(\sprintf('The request needs "%s" in _meta.', self::VERSION_KEY), 400);
        }

        if (!\in_array('' === $version ? '2025-03-26' : $version, self::LEGACY_VERSIONS, true)) {
            throw new ProtocolError(ProtocolError::INVALID_REQUEST, \sprintf('The protocol version "%s" is not supported.', $version), data: [
                'supported' => [...self::VERSIONS, ...self::LEGACY_VERSIONS],
                'requested' => $version,
            ]);
        }

        return match ($method) {
            'tools/list' => $this->list($context),
            'tools/call' => $this->call($params, $context),
            'ping' => [],
            // Not 404: older clients take it for an expired session.
            default => throw new ProtocolError(ProtocolError::METHOD_NOT_FOUND, \sprintf('The method "%s" is not supported.', $method), 200),
        };
    }

    /**
     * The handshake of the versions before 2026-07-28, without a session:
     * nothing of it is kept.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function initialize(array $params): array
    {
        $requested = $params['protocolVersion'] ?? null;

        return [
            'protocolVersion' => \in_array($requested, self::LEGACY_VERSIONS, true) ? $requested : self::LEGACY_VERSIONS[0],
            'capabilities' => self::capabilities(),
            'serverInfo' => self::serverInfo(),
            'instructions' => self::INSTRUCTIONS,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function list(Context $context): array
    {
        $tools = [];

        foreach ($this->tools->of($context->actor) as $name => $descriptor) {
            $tools[] = Tools::definition($name, $descriptor);
        }

        return ['tools' => $tools];
    }

    /**
     * Runs a tool. What the model can fix (input, permission, a missing
     * page) comes back as a result with isError, so it can try again.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function call(array $params, Context $context): array
    {
        $name = self::toolName($params);
        $descriptor = $this->tools->of($context->actor)[$name] ?? throw ProtocolError::invalidParams(\sprintf('Unknown tool: %s', $name));
        $arguments = self::object($params['arguments'] ?? [], 'arguments');

        try {
            $data = Normalizer::normalize($this->runner->call($descriptor->name, $arguments, $context));
        } catch (Throwable $error) {
            $problem = Problem::from($error, $this->environment->isDevelopment());

            if ($problem->status >= 500) {
                $this->logger->error('MCP tool {tool} failed: {message}', ['tool' => $descriptor->name, 'message' => $error->getMessage(), 'exception' => $error]);

                throw new ProtocolError(ProtocolError::INTERNAL_ERROR, $problem->detail, 500);
            }

            // The detail names every field, e.g. 'Invalid input for "page.show": /id: has the wrong format.'
            return ['content' => [['type' => 'text', 'text' => $problem->detail]], 'structuredContent' => $problem->toArray(), 'isError' => true];
        }

        $content = [['type' => 'text', 'text' => self::encode($data)]];
        $structured = ['data' => $data];
        $undo = $descriptor->isAction() ? $this->journal->lastUndoableEntryId() : null;

        if (null !== $undo) {
            $content[] = ['type' => 'text', 'text' => \sprintf('To undo this, call action_undo with {"id": %d}.', $undo)];
            $structured['undo'] = ['tool' => 'action_undo', 'arguments' => ['id' => $undo]];
        }

        return ['content' => $content, 'structuredContent' => $structured, 'isError' => false];
    }

    /**
     * Browsers send an Origin; it must be the website itself, against DNS
     * rebinding. Other clients send none.
     */
    private static function allowsOrigin(ServerRequestInterface $request): bool
    {
        if (!$request->hasHeader('Origin')) {
            return true;
        }

        $origin = parse_url($request->getHeaderLine('Origin'));
        $uri = $request->getUri();

        return \is_array($origin)
            && strtolower($origin['host'] ?? '') === strtolower($uri->getHost())
            && ($origin['port'] ?? null) === $uri->getPort();
    }

    /**
     * @return array<string, mixed>
     */
    private static function message(ServerRequestInterface $request): array
    {
        try {
            $message = json_decode((string) $request->getBody(), true, 64, \JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ProtocolError(ProtocolError::PARSE_ERROR, 'The body is not valid JSON.');
        }

        if (!\is_array($message) || array_is_list($message)) {
            throw new ProtocolError(ProtocolError::INVALID_REQUEST, 'Send one JSON-RPC message as an object; batches are not supported.');
        }

        $message = self::keyed($message);

        if ('2.0' !== ($message['jsonrpc'] ?? null)) {
            throw new ProtocolError(ProtocolError::INVALID_REQUEST, 'The message needs "jsonrpc": "2.0".');
        }

        return $message;
    }

    /**
     * @return array<string, mixed>
     */
    private static function object(mixed $value, string $name, int $status = 200): array
    {
        if (!\is_array($value) || ([] !== $value && array_is_list($value))) {
            throw ProtocolError::invalidParams(\sprintf('"%s" must be an object.', $name), $status);
        }

        return self::keyed($value);
    }

    /**
     * @param array<string, mixed> $params
     */
    private static function toolName(array $params): string
    {
        $name = $params['name'] ?? null;

        return \is_string($name) && '' !== $name ? $name : throw ProtocolError::invalidParams('The tool needs a "name".');
    }

    /**
     * Header values that are not plain ASCII come as "=?base64?…?=".
     */
    private static function decodeHeader(string $value): string
    {
        if (1 === preg_match('/^=\?base64\?([A-Za-z0-9+\/=]*)\?=$/', $value, $match)) {
            $decoded = base64_decode($match[1], true);

            return false === $decoded ? '' : $decoded;
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private static function capabilities(): array
    {
        return ['tools' => new stdClass()];
    }

    /**
     * @return array{name: string, version: string}
     */
    private static function serverInfo(): array
    {
        return ['name' => 'frootbox', 'version' => Frootbox::version()];
    }

    /**
     * @param array<mixed> $values
     *
     * @return array<string, mixed>
     */
    private static function keyed(array $values): array
    {
        $keyed = [];

        foreach ($values as $key => $value) {
            $keyed[(string) $key] = $value;
        }

        return $keyed;
    }

    private static function encode(mixed $value): string
    {
        return json_encode($value, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function json(array $body, int $status = 200): ResponseInterface
    {
        return $this->responses->createResponse($status)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Cache-Control', 'no-store')
            ->withBody($this->streams->createStream(self::encode($body)));
    }

    private function error(int|string|null $id, ProtocolError $error): ResponseInterface
    {
        $body = ['jsonrpc' => '2.0'];

        if (null !== $id) {
            $body['id'] = $id;
        }

        $body['error'] = ['code' => $error->rpcCode, 'message' => $error->getMessage()];

        if (null !== $error->data) {
            $body['error']['data'] = $error->data;
        }

        return $this->json($body, $error->status);
    }
}
