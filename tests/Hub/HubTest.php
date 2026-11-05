<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Hub;

use Frootbox\Core\Action\ActionLog;
use Frootbox\Core\Action\InvalidInputException;
use Frootbox\Core\Action\Journal;
use Frootbox\Core\Application;
use Frootbox\Core\Auth\AccessTokens;
use Frootbox\Core\Config\Config;
use Frootbox\Core\Config\Environment;
use Frootbox\Core\Frootbox;
use Frootbox\Core\Http\MiddlewareDispatcher;
use Frootbox\Core\Http\NotFoundHandler;
use Frootbox\Core\Hub\CompleteHub;
use Frootbox\Core\Hub\ConnectHub;
use Frootbox\Core\Hub\DisconnectHub;
use Frootbox\Core\Hub\Hub;
use Frootbox\Core\Hub\HubAccess;
use Frootbox\Core\Hub\HubAddress;
use Frootbox\Core\Hub\HubCallbackHandler;
use Frootbox\Core\Hub\HubClient;
use Frootbox\Core\Hub\HubConnectCommand;
use Frootbox\Core\Hub\HubConnection;
use Frootbox\Core\Hub\HubConnections;
use Frootbox\Core\Hub\HubDisconnectCommand;
use Frootbox\Core\Hub\HubException;
use Frootbox\Core\Hub\SeenMessages;
use Frootbox\Core\Hub\ShowHub;
use Frootbox\Core\Hub\SwitchHub;
use Frootbox\Core\Hub\UpdateHubKeys;
use Frootbox\Core\Routing\Router;
use Frootbox\Core\Security\Secrets;
use Frootbox\Core\Security\StandardWebhooks;
use Frootbox\Core\Tests\Support\NotesTestCase;
use Frootbox\Core\User\Roles;
use Frootbox\Core\User\Users;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(Hub::class)]
#[CoversClass(HubAccess::class)]
#[CoversClass(HubAddress::class)]
#[CoversClass(HubCallbackHandler::class)]
#[CoversClass(HubClient::class)]
#[CoversClass(HubConnection::class)]
#[CoversClass(HubConnections::class)]
#[CoversClass(HubException::class)]
#[CoversClass(ConnectHub::class)]
#[CoversClass(CompleteHub::class)]
#[CoversClass(SwitchHub::class)]
#[CoversClass(DisconnectHub::class)]
#[CoversClass(UpdateHubKeys::class)]
#[CoversClass(ShowHub::class)]
#[CoversClass(SeenMessages::class)]
#[CoversClass(HubConnectCommand::class)]
#[CoversClass(HubDisconnectCommand::class)]
final class HubTest extends NotesTestCase
{
    private const string SITE = 'https://www.bohnenglueck.de';

    private FakeHub $hub;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hub = new FakeHub();
        $this->container->set(HubClient::class, new HubClient($this->get(Config::class), $this->get(Secrets::class), $this->hub->client()));
    }

    public function testConnectsThroughTheBrowserAndTheHubReadsOnlyTheStatus(): void
    {
        try {
            $this->runner()->run(new ConnectHub(return: 'https://evil.example.org/cms/hub'));
            self::fail('The hub was sent to another website.');
        } catch (InvalidInputException $error) {
            self::assertSame(['/return' => 'must be an address of this website, https://www.bohnenglueck.de/….'], $error->errors);
        }

        $request = $this->runner()->run(new ConnectHub(return: self::SITE . '/cms/hub'));

        self::assertSame('req_0123456789', $request->id);
        self::assertSame(FakeHub::URL . '/connect/req_0123456789', $request->url, 'The browser goes to the hub, whatever the answer says.');
        $told = $this->hub->last('POST', '/connect/v1/requests');
        self::assertSame(self::SITE, $told['url']);
        self::assertSame('Bohnenglück', $told['name']);
        self::assertSame(['name' => 'frootbox', 'version' => Frootbox::version()], $told['system']);
        self::assertSame(['status' => true, 'packages' => false, 'login' => false], $told['switches']);
        self::assertSame(self::SITE . '/cms/hub', $told['return']);
        self::assertSame('pending', $this->runner()->ask(new ShowHub())->state);

        // The hub checks that the server at the address holds the key.
        $verify = $this->fromHub('verify', ['id' => 'req_0123456789', 'challenge' => 'Zufall-0123456789abcdef']);
        self::assertSame(200, $verify->getStatusCode(), (string) $verify->getBody());
        $answer = json_decode((string) $verify->getBody(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($answer);
        $signature = $answer['signature'] ?? null;
        self::assertIsString($signature);
        self::assertNotNull($this->hub->siteKey);
        self::assertTrue(StandardWebhooks::verifyContent($this->hub->siteKey, 'frootbox-connect-verify-v1:req_0123456789:Zufall-0123456789abcdef', $signature));
        self::assertSame(404, $this->fromHub('verify', ['id' => 'req_another0000', 'challenge' => 'Zufall-0123456789abcdef'])->getStatusCode());

        $details = $this->runner()->run(new CompleteHub('ABCD-1234', 'req_0123456789'));

        self::assertSame('connected', $details->state);
        self::assertSame('con_0123456789', $details->connectionId);
        self::assertSame('Herr und Frau Pixel', $details->organization);
        self::assertSame(['code' => 'ABCD-1234'], array_intersect_key($this->hub->last('POST', '/connect/v1/requests/req_0123456789/complete'), ['code' => true]));
        self::assertNotNull($this->hub->status);
        self::assertSame(self::SITE . '/api/v1/status.show', $this->hub->status['url']);
        self::assertNull($details->lastPoll);

        // The hub reads the status with its token, and nothing else.
        self::assertSame(200, $this->api('/api/v1/status.show', $this->hub->status['token'])->getStatusCode());
        self::assertSame(403, $this->api('/api/v1/user.list', $this->hub->status['token'])->getStatusCode());
        self::assertSame(403, $this->api('/api/v1/system.hub-show', $this->hub->status['token'])->getStatusCode());
        self::assertNotNull($this->runner()->ask(new ShowHub())->lastPoll);

        $input = $this->connection()->fetchOne('SELECT input FROM ' . ActionLog::ENTRIES . " WHERE action = 'system.hub-complete'");
        self::assertIsString($input);
        self::assertStringNotContainsString('ABCD-1234', $input, 'The code is not logged.');
        $secret = $this->connection()->fetchOne('SELECT secret_key FROM ' . HubConnections::TABLE);
        self::assertIsString($secret);
        self::assertStringNotContainsString($secret, (string) json_encode($this->connection()->fetchAllAssociative('SELECT * FROM ' . ActionLog::CHANGES)), 'Nor the key, not even encrypted.');
    }

    public function testRefusesACodeForAnotherOrAnExpiredRequest(): void
    {
        $this->runner()->run(new ConnectHub());
        $this->assertNoRequest(new CompleteHub('ABCD-1234', 'req_another0000'));

        $this->get(Journal::class)->trusted(fn(): int|string => $this->connection()->update(HubConnections::TABLE, ['request_expires_at' => '2020-01-01 00:00:00'], ['request_id' => 'req_0123456789']));
        $this->assertNoRequest(new CompleteHub('ABCD-1234', 'req_0123456789'));
        self::assertSame('none', $this->runner()->ask(new ShowHub())->state);

        $this->runner()->run(new ConnectHub());
        $this->runner()->run(new CompleteHub('ABCD-1234', 'req_0123456789'));

        $this->expectException(HubException::class);
        $this->expectExceptionMessage('connected already');
        $this->runner()->run(new ConnectHub());
    }

    public function testTheCodeAloneConnectsFromTheConsole(): void
    {
        $tester = new CommandTester($this->get(HubConnectCommand::class));
        $tester->execute(['code' => 'K7Q2-M9XD']);

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('for Herr und Frau Pixel', $tester->getDisplay());
        $told = $this->hub->last('POST', '/connect/v1/codes/K7Q2-M9XD');
        self::assertSame(self::SITE, $told['url']);
        self::assertIsArray($told['status']);

        $tester = new CommandTester($this->get(HubConnectCommand::class));
        $tester->execute(['code' => 'K7Q2-M9XD']);
        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('connected already', $tester->getDisplay());
    }

    public function testStatusOffBlocksTheTokenAtOnceEvenIfTheHubIsDown(): void
    {
        $this->runner()->run(new CompleteHub('K7Q2-M9XD'));
        self::assertNotNull($this->hub->status);
        $token = $this->hub->status['token'];

        $this->hub->down = true;
        $details = $this->runner()->run(new SwitchHub(false));

        self::assertFalse($details->switches['status']);
        self::assertNull($this->get(AccessTokens::class)->authenticate($token));
        self::assertNull($this->get(Roles::class)->findByName(HubAccess::ROLE));

        $this->hub->down = false;
        $this->runner()->run(new SwitchHub(true));
        self::assertNotNull($this->hub->status);
        self::assertNotSame($token, $this->hub->status['token']);
        self::assertSame(['status' => true, 'packages' => false, 'login' => false], $this->hub->last('PATCH', '/connect/v1/connections/con_0123456789')['switches']);
        self::assertSame(200, $this->api('/api/v1/status.show', $this->hub->status['token'])->getStatusCode());
    }

    public function testDisconnectsAtOnceEvenIfTheHubIsDownAndKeepsTheKey(): void
    {
        $this->runner()->run(new CompleteHub('K7Q2-M9XD'));
        $key = $this->runner()->ask(new ShowHub())->publicKey;
        self::assertNotNull($this->hub->status);
        $token = $this->hub->status['token'];

        $this->hub->down = true;
        $details = $this->runner()->run(new DisconnectHub());

        self::assertSame('none', $details->state);
        self::assertNull($this->get(AccessTokens::class)->authenticate($token));
        self::assertSame(0, $this->get(Users::class)->count(['kind' => 'service']));

        $this->hub->down = false;
        $this->runner()->run(new CompleteHub('K7Q2-M9XD'));
        self::assertSame($key, $this->runner()->ask(new ShowHub())->publicKey, 'The key pair stays for the next connection.');

        $this->runner()->run(new DisconnectHub());
        self::assertSame([], $this->hub->last('DELETE', '/connect/v1/connections/con_0123456789'), 'Reachable, the hub is told.');
    }

    public function testACopyForgetsConnectionAndKeyWithoutTellingTheHub(): void
    {
        $this->runner()->run(new CompleteHub('K7Q2-M9XD'));
        $this->hub->requests = [];

        $tester = new CommandTester($this->get(HubDisconnectCommand::class));
        $tester->execute(['--local' => true, '--forget-key' => true]);

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertSame([], $this->hub->requests, 'The original stays connected.');
        self::assertNull($this->get(HubConnections::class)->current());
        self::assertSame(0, $this->get(Users::class)->count(['kind' => 'service']));

        $tester->execute(['--local' => true, '--forget-key' => true]);
        self::assertSame(0, $tester->getStatusCode(), 'Nothing to forget is fine.');
    }

    public function testTheHubDisconnectsSignedAndEachMessageCountsOnce(): void
    {
        $this->runner()->run(new CompleteHub('K7Q2-M9XD'));
        $body = '{"connection":"con_0123456789"}';

        self::assertSame(401, $this->fromHub('disconnect', [], $body, $this->hub->sign($body, StandardWebhooks::keyPair()['secret']))->getStatusCode(), 'Another key.');
        self::assertSame(401, $this->fromHub('disconnect', [], $body, ['webhook-timestamp' => (string) (time() - 600)] + $this->hub->sign($body))->getStatusCode(), 'Too old.');
        self::assertSame(404, $this->fromHub('disconnect', ['connection' => 'con_another0000'])->getStatusCode());

        $verify = '{"id":"con_0123456789","challenge":"Zufall-0123456789abcdef"}';
        $headers = $this->hub->sign($verify, id: 'msg_once');
        self::assertSame(200, $this->fromHub('verify', [], $verify, $headers)->getStatusCode(), 'The address can be confirmed later, e.g. after the console.');
        self::assertSame(409, $this->fromHub('verify', [], $verify, $headers)->getStatusCode(), 'The same message again.');

        $response = $this->fromHub('disconnect', [], $body);
        self::assertSame(204, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('none', $this->runner()->ask(new ShowHub())->state);
        self::assertSame(0, $this->get(Users::class)->count(['kind' => 'service']));
        self::assertSame([], array_filter($this->hub->requests, static fn(array $request): bool => 'DELETE' === $request['method']), 'The hub knows already.');
        self::assertSame(404, $this->fromHub('disconnect', [], $body)->getStatusCode());
    }

    public function testTakesNewKeysOfTheHubOnlySignedWithACurrentOne(): void
    {
        $this->runner()->run(new CompleteHub('K7Q2-M9XD'));
        $new = StandardWebhooks::keyPair();
        $body = json_encode(['keys' => [['id' => 'k2', 'key' => $new['public']]]], \JSON_THROW_ON_ERROR);

        self::assertSame(401, $this->fromHub('keys', [], $body, $this->hub->sign($body, $new['secret']))->getStatusCode(), 'A new key cannot announce itself.');
        self::assertSame(422, $this->fromHub('keys', ['keys' => ['whpk_broken']])->getStatusCode());
        self::assertSame(204, $this->fromHub('keys', [], $body)->getStatusCode());

        $verify = ['id' => 'con_0123456789', 'challenge' => 'Zufall-0123456789abcdef'];
        self::assertSame(401, $this->fromHub('verify', $verify)->getStatusCode(), 'The old key is gone.');
        $json = json_encode($verify, \JSON_THROW_ON_ERROR);
        self::assertSame(200, $this->fromHub('verify', [], $json, $this->hub->sign($json, $new['secret']))->getStatusCode());
    }

    public function testTheCallbacksPassThePasswordOfTheWebsite(): void
    {
        self::assertContains('.well-known', $this->get(Router::class)->routeSegments());
        self::assertNotContains('.well-known', $this->get(Router::class)->reservedSegments(), 'No slug can take it anyway.');
        self::assertSame(404, $this->fromHub('verify', ['id' => 'req_0123456789', 'challenge' => 'Zufall-0123456789abcdef'])->getStatusCode(), 'Without a connection there is nothing to confirm.');
    }

    public function testSaysWhyTheWebsiteCannotConnect(): void
    {
        self::assertNull($this->hint());
        self::assertSame('secret', $this->hint(secret: ''));
        self::assertSame('address', $this->hint(['url' => null]));
        self::assertSame('local', $this->hint(['url' => 'http://bohnenglueck.fb.localhost']));
        self::assertNull($this->hint(['url' => 'http://bohnenglueck.fb.localhost'], ['url' => 'http://hub.localhost:8400']), 'A local hub takes local websites, for development.');
        self::assertSame('hub-url', $this->hint(hub: ['url' => 'http://hub.example.org']), 'Only a local hub goes without HTTPS.');

        foreach (['http://localhost:8000', 'http://127.0.0.1', 'https://192.168.1.20', 'https://[::1]', 'https://intranet', 'https://shop.test', 'http://site.fb.localhost'] as $local) {
            self::assertTrue(HubAddress::isLocal($local), $local);
        }

        foreach (['https://www.bohnenglueck.de', 'https://93.184.215.14', 'https://staging.example.com'] as $public) {
            self::assertFalse(HubAddress::isLocal($public), $public);
        }

        $this->expectException(HubException::class);
        $this->expectExceptionMessage('local address');
        $this->container->set(Config::class, $this->configWith(['url' => 'http://bohnenglueck.fb.localhost']));
        $this->runner()->run(new ConnectHub());
    }

    protected function config(): array
    {
        return ['app' => ['name' => 'Bohnenglück', 'url' => self::SITE], 'hub' => ['url' => FakeHub::URL]];
    }

    private function assertNoRequest(CompleteHub $action): void
    {
        try {
            $this->runner()->run($action);
            self::fail('A code was redeemed without its request.');
        } catch (HubException $error) {
            self::assertStringContainsString('No request waits', $error->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $app
     * @param array<string, mixed> $hub
     */
    private function hint(array $app = [], array $hub = [], string $secret = self::SECRET): ?string
    {
        $config = $this->configWith($app, $hub, $secret);
        $secrets = new Secrets($config);

        return new Hub($this->get(HubConnections::class), new HubClient($config, $secrets, $this->hub->client()), $config, $secrets, $this->get(AccessTokens::class))->hint();
    }

    /**
     * @param array<string, mixed> $app
     * @param array<string, mixed> $hub
     */
    private function configWith(array $app = [], array $hub = [], string $secret = self::SECRET): Config
    {
        $config = ['app' => [...['name' => 'Bohnenglück', 'url' => self::SITE], ...$app], 'hub' => [...['url' => FakeHub::URL], ...$hub]];

        return new Config(Environment::Development, $config, ['APP_SECRET' => $secret]);
    }

    /**
     * A message of the hub to the website, signed with its key unless
     * headers are given.
     *
     * @param array<string, mixed>  $data
     * @param array<string, string> $headers
     */
    private function fromHub(string $name, array $data, ?string $body = null, ?array $headers = null): ResponseInterface
    {
        $body ??= json_encode($data, \JSON_THROW_ON_ERROR);
        $factory = new Psr17Factory();
        $request = $factory->createServerRequest('POST', 'http://localhost/.well-known/frootbox/' . $name)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Accept', 'application/json')
            ->withBody($factory->createStream($body));

        foreach ($headers ?? $this->hub->sign($body) as $header => $value) {
            $request = $request->withHeader($header, $value);
        }

        return $this->dispatch($request);
    }

    private function api(string $path, string $token): ResponseInterface
    {
        return $this->dispatch(new Psr17Factory()->createServerRequest('GET', 'http://localhost' . $path)->withHeader('Authorization', 'Bearer ' . $token));
    }

    private function dispatch(\Psr\Http\Message\ServerRequestInterface $request): ResponseInterface
    {
        $middleware = array_map($this->get(...), Application::MIDDLEWARE);

        return new MiddlewareDispatcher($middleware, $this->get(NotFoundHandler::class))->handle($request);
    }
}
