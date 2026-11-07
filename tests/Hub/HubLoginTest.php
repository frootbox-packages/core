<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Hub;

use Frootbox\Core\Action\Channel;
use Frootbox\Core\Action\Context;
use Frootbox\Core\Application;
use Frootbox\Core\Auth\AccessTokens;
use Frootbox\Core\Auth\Actor;
use Frootbox\Core\Auth\Oidc\BackChannelLogout;
use Frootbox\Core\Auth\Oidc\CompletedSignIn;
use Frootbox\Core\Auth\Oidc\CompleteSignIn;
use Frootbox\Core\Auth\Oidc\GrantAccess;
use Frootbox\Core\Auth\Oidc\LinkAccount;
use Frootbox\Core\Auth\Oidc\ListIdentities;
use Frootbox\Core\Auth\Oidc\ListProviders;
use Frootbox\Core\Auth\Oidc\LogoutHandler;
use Frootbox\Core\Auth\Oidc\OidcAccounts;
use Frootbox\Core\Auth\Oidc\OidcClient;
use Frootbox\Core\Auth\Oidc\OidcException;
use Frootbox\Core\Auth\Oidc\OidcPaths;
use Frootbox\Core\Auth\Oidc\PrivateKeyJwt;
use Frootbox\Core\Auth\Oidc\Provider;
use Frootbox\Core\Auth\Oidc\ProviderInfo;
use Frootbox\Core\Auth\Oidc\Providers;
use Frootbox\Core\Auth\Oidc\RevokeAccess;
use Frootbox\Core\Auth\Oidc\RoleLimit;
use Frootbox\Core\Auth\Oidc\SeenTokens;
use Frootbox\Core\Auth\Oidc\SignInState;
use Frootbox\Core\Auth\Oidc\SignInStates;
use Frootbox\Core\Auth\Oidc\StartSignIn;
use Frootbox\Core\Auth\Oidc\UnlinkAccount;
use Frootbox\Core\Auth\Oidc\UserIdentities;
use Frootbox\Core\Auth\TokenType;
use Frootbox\Core\Auth\TooManyAttemptsException;
use Frootbox\Core\Config\Config;
use Frootbox\Core\Http\MiddlewareDispatcher;
use Frootbox\Core\Http\NotFoundHandler;
use Frootbox\Core\Hub\CompleteHub;
use Frootbox\Core\Hub\DisconnectHub;
use Frootbox\Core\Hub\HubClient;
use Frootbox\Core\Hub\HubLogin;
use Frootbox\Core\Hub\SwitchHub;
use Frootbox\Core\Security\Secrets;
use Frootbox\Core\Tests\Support\NotesTestCase;
use Frootbox\Core\User\CreateUser;
use Frootbox\Core\User\RoleAssignments;
use Frootbox\Core\User\SetRoles;
use Frootbox\Core\User\User;
use Frootbox\Core\User\Users;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

#[CoversClass(HubLogin::class)]
#[CoversClass(SwitchHub::class)]
#[CoversClass(StartSignIn::class)]
#[CoversClass(CompleteSignIn::class)]
#[CoversClass(CompletedSignIn::class)]
#[CoversClass(LinkAccount::class)]
#[CoversClass(UnlinkAccount::class)]
#[CoversClass(BackChannelLogout::class)]
#[CoversClass(LogoutHandler::class)]
#[CoversClass(GrantAccess::class)]
#[CoversClass(RevokeAccess::class)]
#[CoversClass(ListProviders::class)]
#[CoversClass(ListIdentities::class)]
#[CoversClass(OidcAccounts::class)]
#[CoversClass(OidcClient::class)]
#[CoversClass(OidcException::class)]
#[CoversClass(OidcPaths::class)]
#[CoversClass(PrivateKeyJwt::class)]
#[CoversClass(Provider::class)]
#[CoversClass(ProviderInfo::class)]
#[CoversClass(Providers::class)]
#[CoversClass(RoleLimit::class)]
#[CoversClass(SeenTokens::class)]
#[CoversClass(SignInState::class)]
#[CoversClass(SignInStates::class)]
#[CoversClass(UserIdentities::class)]
final class HubLoginTest extends NotesTestCase
{
    private const string SITE = 'https://www.bohnenglueck.de';

    private FakeHub $hub;

    private FakeOidcProvider $oidc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hub = new FakeHub();
        $this->oidc = new FakeOidcProvider($this->hub);
        $this->container->set(HubClient::class, new HubClient($this->get(Config::class), $this->get(Secrets::class), $this->hub->client()));
        $this->container->set(OidcClient::class, $this->oidc->client());
        $this->runner()->run(new CompleteHub('K7Q2-M9XD'));
    }

    public function testSwitchingOnTellsTheHubWhereAndOffEndsTheSessionsAtOnce(): void
    {
        self::assertSame([], $this->runner()->ask(new ListProviders()), 'Off by default.');

        $details = $this->runner()->run(new SwitchHub(login: true));

        self::assertTrue($details->switches['login']);
        self::assertSame('admin', $details->loginMaxRole, 'admin unless chosen otherwise.');
        $told = $this->hub->last('PATCH', '/connect/v1/connections/con_0123456789');
        self::assertSame(['status' => true, 'packages' => false, 'login' => true], $told['switches']);
        self::assertArrayNotHasKey('status', $told, 'The status stays as it is.');
        self::assertSame([
            'redirectUri' => self::SITE . '/cms/login/oidc/hub',
            'logoutUri' => self::SITE . '/_oidc/hub/logout',
            'initiateUri' => self::SITE . '/cms/login/oidc/hub/start',
            'maxRole' => 'admin',
        ], $told['login']);
        self::assertEquals([new ProviderInfo('hub', 'example.org', FakeHub::URL)], $this->runner()->ask(new ListProviders()));

        $this->runner()->run(new SwitchHub(maxRole: 'superuser'));
        $login = $this->hub->last('PATCH', '/connect/v1/connections/con_0123456789')['login'] ?? null;
        self::assertIsArray($login);
        self::assertSame('superuser', $login['maxRole'] ?? null);

        $signedIn = $this->signIn();
        self::assertNotNull($signedIn->token);
        self::assertNotNull($this->get(AccessTokens::class)->authenticate($signedIn->token));

        $this->hub->down = true;
        $details = $this->runner()->run(new SwitchHub(login: false));

        self::assertFalse($details->switches['login']);
        self::assertNull($this->get(AccessTokens::class)->authenticate($signedIn->token), 'Off ends the sessions through the hub, even if the hub is down.');
        self::assertSame([], $this->runner()->ask(new ListProviders()));
        self::assertCount(1, $this->get(UserIdentities::class)->forUser($signedIn->user->id), 'The link stays for switching on again.');

        $this->hub->down = false;
        $this->runner()->run(new SwitchHub(login: true));
        $again = $this->signIn();
        self::assertSame($signedIn->user->id, $again->user->id, 'Nobody is created anew.');

        $this->runner()->run(new DisconnectHub());
        self::assertNotNull($again->token);
        self::assertNull($this->get(AccessTokens::class)->authenticate($again->token), 'Disconnecting ends them too.');
    }

    public function testAPersonWithAccessGetsAnAccountManagedByTheHub(): void
    {
        $this->runner()->run(new SwitchHub(login: true));
        $signedIn = $this->signIn();

        self::assertFalse($signedIn->linked);
        self::assertSame('/cms/pages', $signedIn->next);
        self::assertSame('anna@example.org', $signedIn->user->email);
        self::assertSame('Anna Hub', $signedIn->user->name);
        self::assertSame(['admin'], $this->roles($signedIn->user));
        self::assertNull($this->get(Users::class)->passwordHash($signedIn->user->id), 'No password: the hub manages the account.');
        self::assertNotNull($signedIn->session);
        self::assertSame('hub', $signedIn->session->provider);
        self::assertSame('ses_1', $signedIn->session->providerSession);
        self::assertNotNull($signedIn->session->expiresAt);
        self::assertLessThanOrEqual(time() + 12 * 3600, $signedIn->session->expiresAt->getTimestamp(), 'At most 12 hours.');
        self::assertFalse($signedIn->session->remember);

        $identities = $this->runner()->ask(new ListIdentities(), new Context(Actor::user($signedIn->user->id, $signedIn->user->name), Channel::Web));
        self::assertCount(1, $identities);
        self::assertTrue($identities[0]->managed);
        self::assertSame('example.org', $identities[0]->title);

        // The role follows the hub with every sign-in, capped by maxRole.
        $this->oidc->role = 'editor';
        self::assertSame(['editor'], $this->roles($this->signIn()->user));
        $this->oidc->role = 'superuser';
        self::assertSame(['admin'], $this->roles($this->signIn()->user), 'Never above maxRole.');

        $this->oidc->role = null;
        $this->expectException(OidcException::class);
        $this->expectExceptionMessage('no access to this website');
        $this->signIn();
    }

    public function testNeverLinksALocalAccountBySignInAndNeverOpensMoreThanTheHubMayGive(): void
    {
        $this->runner()->run(new SwitchHub(login: true));
        $local = $this->runner()->run(new CreateUser('Anna@example.org', 'Anna', 'ein-langes-passwort', ['superuser']))->user;

        try {
            $this->signIn();
            self::fail('A local account was opened through its email.');
        } catch (OidcException $error) {
            self::assertStringContainsString('exists on this website already', $error->getMessage());
        }

        // The owner links it under My account.
        $as = new Context(Actor::user($local->id, $local->name), Channel::Web);
        $linked = $this->signIn($as, link: true);
        self::assertTrue($linked->linked);
        self::assertNull($linked->token, 'Linking starts no session.');
        self::assertFalse($this->get(UserIdentities::class)->forUser($local->id)[0]->managed);

        try {
            $this->signIn();
            self::fail('The hub opened a superuser with admin.');
        } catch (OidcException $error) {
            self::assertStringContainsString('more rights here than', $error->getMessage());
        }

        $this->runner()->run(new SwitchHub(maxRole: 'superuser'));
        $this->oidc->role = 'superuser';
        $signedIn = $this->signIn();
        self::assertSame($local->id, $signedIn->user->id);
        self::assertSame(['superuser'], $this->roles($signedIn->user), 'A linked account keeps its local roles.');
        self::assertNotNull($this->get(Users::class)->passwordHash($local->id), 'And its password.');

        $this->runner()->run(new UnlinkAccount('hub'), $as);
        self::assertSame([], $this->get(UserIdentities::class)->forUser($local->id));
        self::assertNotNull($signedIn->token);
        self::assertNull($this->get(AccessTokens::class)->authenticate($signedIn->token), 'Unlinking ends the sessions through the hub.');
    }

    public function testChecksStateNonceAudienceAndThrottles(): void
    {
        $this->runner()->run(new SwitchHub(login: true));
        $guest = Context::guest(address: '203.0.113.7');

        $started = $this->runner()->run(new StartSignIn('hub'), $guest);
        $back = $this->oidc->authorize($started->url);
        $this->assertRefused(new CompleteSignIn('hub', $back['code'], 'another-state', $started->stored), 'from another window', $guest);
        $other = $this->runner()->run(new StartSignIn('hub'), $guest);
        $this->assertRefused(new CompleteSignIn('hub', $back['code'], $back['state'], $other->stored), 'from another window', $guest);

        $this->oidc->tamper = ['aud' => 'con_another0000'];
        $this->assertRefused(new CompleteSignIn('hub', $back['code'], $back['state'], $started->stored), 'for another client', $guest);
        $this->oidc->tamper = [];
        $this->assertRefused(new CompleteSignIn('hub', $back['code'], $back['state'], $started->stored), 'invalid_grant', $guest, 'A code counts once.');

        $started = $this->runner()->run(new StartSignIn('hub'), $guest);
        $back = $this->oidc->authorize($started->url);
        $this->oidc->tamper = ['nonce' => 'another-nonce'];
        $this->assertRefused(new CompleteSignIn('hub', $back['code'], $back['state'], $started->stored), 'nonce', $guest);

        $this->oidc->tamper = [];
        $started = $this->runner()->run(new StartSignIn('hub'), $guest);
        $back = $this->oidc->authorize($started->url);
        $this->expectException(TooManyAttemptsException::class);
        $this->runner()->run(new CompleteSignIn('hub', $back['code'], $back['state'], $started->stored), $guest);
    }

    public function testTheBackChannelEndsTheSessionsOfASignInOrOfAPerson(): void
    {
        $this->runner()->run(new SwitchHub(login: true));
        $first = $this->signIn();
        $this->oidc->person['sid'] = 'ses_2';
        $second = $this->signIn();
        $password = $this->get(AccessTokens::class)->issue($first->user->id, TokenType::Session);
        self::assertNotNull($first->token);
        self::assertNotNull($second->token);

        self::assertSame(400, $this->logout('kein Token')->getStatusCode());
        self::assertSame(400, $this->logout($this->oidc->logoutToken(['sid' => 'ses_1'], sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair())))->getStatusCode(), 'Another key.');
        self::assertSame(400, $this->logout($this->oidc->logoutToken(['sid' => 'ses_1', 'iat' => time() - 900]))->getStatusCode(), 'Too old.');
        self::assertSame(400, $this->logout($this->oidc->logoutToken(['sid' => 'ses_1', 'nonce' => 'x']))->getStatusCode(), 'No ID token.');

        $token = $this->oidc->logoutToken(['sid' => 'ses_1']);
        $response = $this->logout($token);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertNull($this->get(AccessTokens::class)->authenticate($first->token));
        self::assertNotNull($this->get(AccessTokens::class)->authenticate($second->token), 'Only the sessions of that sign-in.');
        self::assertSame(400, $this->logout($token)->getStatusCode(), 'Each token counts once.');

        self::assertSame(200, $this->logout($this->oidc->logoutToken(['sub' => 'usr_anna']))->getStatusCode());
        self::assertNull($this->get(AccessTokens::class)->authenticate($second->token));
        self::assertNotNull($this->get(AccessTokens::class)->authenticate($password->token), 'Sessions with a password stay.');
    }

    public function testTheHubManagesAccountsOnBehalfOfAPersonWithItsRights(): void
    {
        $this->runner()->run(new SwitchHub(login: true));
        $person = ['sub' => 'usr_ben', 'email' => 'ben@example.org', 'email_verified' => true, 'name' => 'Ben'];
        $actor = ['sub' => 'usr_anna', 'role' => 'admin'];

        self::assertSame(404, $this->access($actor, $person, 'editor')->getStatusCode(), 'The website does not know Anna yet.');

        // Anna signs in once through the hub, as admin.
        $anna = $this->signIn()->user;

        $response = $this->access($actor, $person, 'editor');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('created', $this->json($response)['result'] ?? null);
        $ben = $this->get(Users::class)->findByEmail('ben@example.org');
        self::assertNotNull($ben);
        self::assertSame(['editor'], $this->roles($ben));
        $entry = $this->connection()->fetchAssociative("SELECT actor_id, channel FROM action_log WHERE action = 'user.grant-access'");
        self::assertIsArray($entry);
        self::assertSame([$anna->id, 'hub'], [is_numeric($entry['actor_id']) ? (int) $entry['actor_id'] : null, $entry['channel']], 'With the rights and the name of Anna.');

        self::assertSame('updated', $this->json($this->access($actor, $person, 'superuser'))['result'] ?? null);
        self::assertSame(['admin'], $this->roles($ben), 'Never above maxRole.');
        $this->runner()->run(new SwitchHub(maxRole: 'superuser'));
        self::assertSame(403, $this->access($actor, $person, 'superuser')->getStatusCode(), 'Never above her own level.');
        self::assertSame(['admin'], $this->roles($ben));
        self::assertSame(403, $this->access(['sub' => 'usr_anna', 'role' => null], $person, 'editor')->getStatusCode());

        // An existing local account with the same email is linked.
        $this->runner()->run(new CreateUser('carla@example.org', 'Carla', 'ein-langes-passwort'));
        $response = $this->access($actor, ['sub' => 'usr_carla', 'email' => 'carla@example.org', 'email_verified' => true, 'name' => 'Carla'], 'admin');
        self::assertSame('linked', $this->json($response)['result'] ?? null, (string) $response->getBody());

        // Taking access away locks the account and ends its sessions.
        $session = $this->get(AccessTokens::class)->issue($ben->id, TokenType::Session);
        $response = $this->access($actor, $person, null);
        self::assertSame('blocked', $this->json($response)['result'] ?? null, (string) $response->getBody());
        self::assertFalse($this->get(Users::class)->getById($ben->id)->active);
        self::assertNull($this->get(AccessTokens::class)->authenticate($session->token));

        self::assertSame('updated', $this->json($this->access($actor, $person, 'editor'))['result'] ?? null);
        self::assertTrue($this->get(Users::class)->getById($ben->id)->active, 'A new access unlocks it.');

        // Anna as superuser locally: more than the hub may give her.
        $this->runner()->run(new SetRoles($anna->id, ['superuser']));
        self::assertSame(409, $this->access($actor, $person, 'editor')->getStatusCode());
    }

    protected function config(): array
    {
        return ['app' => ['name' => 'Bohnenglück', 'url' => self::SITE], 'hub' => ['url' => FakeHub::URL]];
    }

    private function signIn(?Context $context = null, bool $link = false): CompletedSignIn
    {
        $context ??= Context::guest(address: '203.0.113.5');
        $started = $link ? $this->runner()->run(new LinkAccount('hub'), $context) : $this->runner()->run(new StartSignIn('hub', '/cms/pages'), $context);
        $back = $this->oidc->authorize($started->url);

        return $this->runner()->run(new CompleteSignIn('hub', $back['code'], $back['state'], $started->stored, 'Firefox'), $context);
    }

    private function assertRefused(CompleteSignIn $action, string $message, Context $context, string $why = ''): void
    {
        try {
            $this->runner()->run($action, $context);
            self::fail('Signed in: ' . $why);
        } catch (OidcException $error) {
            self::assertStringContainsString($message, $error->getMessage(), $why);
        }
    }

    /**
     * @return list<string>
     */
    private function roles(User $user): array
    {
        return array_column($this->get(RoleAssignments::class)->rolesOf($user->id), 'role');
    }

    private function logout(string $token): ResponseInterface
    {
        $factory = new Psr17Factory();
        $request = $factory->createServerRequest('POST', 'http://localhost/_oidc/hub/logout')
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withBody($factory->createStream(http_build_query(['logout_token' => $token])));

        return $this->dispatch($request);
    }

    /**
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $person
     */
    private function access(array $actor, array $person, ?string $role): ResponseInterface
    {
        $body = json_encode(['connection' => 'con_0123456789', 'actor' => $actor, 'person' => $person, 'role' => $role], \JSON_THROW_ON_ERROR);
        $factory = new Psr17Factory();
        $request = $factory->createServerRequest('POST', 'http://localhost/.well-known/frootbox/access')
            ->withHeader('Content-Type', 'application/json')
            ->withBody($factory->createStream($body));

        foreach ($this->hub->sign($body) as $header => $value) {
            $request = $request->withHeader($header, $value);
        }

        return $this->dispatch($request);
    }

    /**
     * @return array<mixed>
     */
    private function json(ResponseInterface $response): array
    {
        $data = json_decode((string) $response->getBody(), true);

        return \is_array($data) ? $data : [];
    }

    private function dispatch(ServerRequestInterface $request): ResponseInterface
    {
        $middleware = array_map($this->get(...), Application::MIDDLEWARE);

        return new MiddlewareDispatcher($middleware, $this->get(NotFoundHandler::class))->handle($request);
    }
}
