<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Site;

use Frootbox\Core\Action\Channel;
use Frootbox\Core\Action\Context;
use Frootbox\Core\Action\CurrentContext;
use Frootbox\Core\Application;
use Frootbox\Core\Auth\AccessTokens;
use Frootbox\Core\Auth\TokenType;
use Frootbox\Core\Config\Environment;
use Frootbox\Core\Extension\Extensions;
use Frootbox\Core\Http\MiddlewareDispatcher;
use Frootbox\Core\Page\CreatePage;
use Frootbox\Core\Page\PageHandler;
use Frootbox\Core\Page\PublishPages;
use Frootbox\Core\Preview\CreatePreview;
use Frootbox\Core\Preview\PreviewAccess;
use Frootbox\Core\Site\EnterSite;
use Frootbox\Core\Site\ProtectSite;
use Frootbox\Core\Site\ShowSite;
use Frootbox\Core\Site\SiteAccessMiddleware;
use Frootbox\Core\Site\SiteProtection;
use Frootbox\Core\Site\UnprotectSite;
use Frootbox\Core\Tests\Support\FixtureSite;
use Frootbox\Core\Tests\Support\NotesTestCase;
use Frootbox\Core\User\CreateUser;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Http\Message\ResponseInterface;

#[CoversClass(SiteProtection::class)]
#[CoversClass(ProtectSite::class)]
#[CoversClass(UnprotectSite::class)]
#[CoversClass(EnterSite::class)]
#[CoversClass(SiteAccessMiddleware::class)]
final class SiteProtectionTest extends NotesTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $start = $this->runner()->run(new CreatePage('acme/base/Page', ['de' => ['title' => 'Start', 'content' => ['fields' => ['heading' => 'Willkommen']]]]))->id;
        $this->runner()->run(new PublishPages([$start]));
    }

    public function testLetsInOnlyWhoKnowsThePassword(): void
    {
        self::assertSame(200, $this->deliver('/')->getStatusCode());
        self::assertFalse($this->runner()->ask(new ShowSite())->protection?->on);

        $status = $this->runner()->run(new ProtectSite('kaffeebohne'));
        self::assertTrue($status->on);
        self::assertTrue($status->password);
        self::assertFalse($status->forced);

        // Strangers get a form, also for a page in the cache and for a missing one.
        $closed = $this->deliver('/');
        self::assertSame(401, $closed->getStatusCode());
        self::assertStringContainsString('name="fb-site-password"', (string) $closed->getBody());
        self::assertSame('noindex, nofollow', $closed->getHeaderLine('X-Robots-Tag'));
        self::assertSame(401, $this->deliver('/gibt-es-nicht')->getStatusCode());

        $robots = $this->deliver('/robots.txt');
        self::assertSame(200, $robots->getStatusCode());
        self::assertSame("User-agent: *\nDisallow: /\n", (string) $robots->getBody());

        $wrong = $this->deliver('/', post: 'falsch');
        self::assertSame(401, $wrong->getStatusCode());
        self::assertStringContainsString('Das Passwort stimmt nicht.', (string) $wrong->getBody());

        $right = $this->deliver('/?ref=mail', post: 'kaffeebohne');
        self::assertSame(303, $right->getStatusCode());
        self::assertSame('/?ref=mail', $right->getHeaderLine('Location'));
        self::assertMatchesRegularExpression('~^frootbox_site_access=[^;]+; Path=/; HttpOnly; SameSite=Lax; Max-Age=1209600; Secure$~', $right->getHeaderLine('Set-Cookie'));
        $cookie = rawurldecode(explode(';', explode('=', $right->getHeaderLine('Set-Cookie'), 2)[1])[0]);

        $inside = $this->deliver('/', cookies: [SiteProtection::COOKIE => $cookie]);
        self::assertSame(200, $inside->getStatusCode());
        self::assertSame('noindex, nofollow', $inside->getHeaderLine('X-Robots-Tag'), 'Not in the index while protected, also for those let in.');
        self::assertSame(401, $this->deliver('/', cookies: [SiteProtection::COOKIE => $cookie . 'x'])->getStatusCode());

        // A new password locks out every browser let in before.
        $this->runner()->run(new ProtectSite('neue-bohne'));
        self::assertSame(401, $this->deliver('/', cookies: [SiteProtection::COOKIE => $cookie])->getStatusCode());

        $this->runner()->run(new UnprotectSite());
        $open = $this->deliver('/');
        self::assertSame(200, $open->getStatusCode());
        self::assertSame('', $open->getHeaderLine('X-Robots-Tag'));
        self::assertSame(404, $this->deliver('/robots.txt')->getStatusCode());
    }

    public function testLetsInUsersPreviewsAndRoutes(): void
    {
        $this->runner()->run(new ProtectSite('kaffeebohne'));
        $user = $this->runner()->run(new CreateUser('editor@example.org', 'Erika', roles: ['editor']), Context::system())->user;
        $token = $this->get(AccessTokens::class)->issue($user->id, TokenType::Api)->token;

        self::assertSame(200, $this->deliver('/', headers: ['Authorization' => 'Bearer ' . $token])->getStatusCode());
        self::assertSame(200, $this->deliver('/api/v1/openapi.json')->getStatusCode(), 'The API checks access itself.');

        $link = $this->runner()->run(new CreatePreview());
        parse_str((string) parse_url((string) $link->path, \PHP_URL_QUERY), $query);
        self::assertIsString($query['fb-preview'] ?? null);
        self::assertSame(303, $this->deliver((string) $link->path)->getStatusCode(), 'A preview link needs no password.');
        self::assertSame(200, $this->deliver('/', cookies: [PreviewAccess::COOKIE => $query['fb-preview']])->getStatusCode());
    }

    public function testThrottlesGuessing(): void
    {
        $this->runner()->run(new ProtectSite('kaffeebohne'));

        for ($i = 0; $i < 5; ++$i) {
            $this->deliver('/', post: 'falsch-' . $i);
        }

        $blocked = $this->deliver('/', post: 'kaffeebohne');
        self::assertSame(401, $blocked->getStatusCode());
        self::assertStringContainsString('Zu viele Versuche', (string) $blocked->getBody());
    }

    public function testChecksThePassword(): void
    {
        $this->expectException(\Frootbox\Core\Action\InvalidInputException::class);
        $this->runner()->run(new ProtectSite('kurz'));
    }

    protected function environment(): Environment
    {
        return Environment::Production;
    }

    protected function extensions(): Extensions
    {
        return FixtureSite::extensions('base', 'site');
    }

    protected function config(): array
    {
        return ['app' => ['name' => 'Testsite', 'url' => 'https://example.org'], 'theme' => 'acme/site', 'languages' => ['de' => ['title' => 'Deutsch']]];
    }

    /**
     * @param array<string, string> $cookies
     * @param array<string, string> $headers
     */
    private function deliver(string $path, ?string $post = null, array $cookies = [], array $headers = []): ResponseInterface
    {
        $request = new ServerRequest(null === $post ? 'GET' : 'POST', 'https://example.org' . $path, $headers, serverParams: ['REMOTE_ADDR' => '203.0.113.7']);
        parse_str((string) parse_url($path, \PHP_URL_QUERY), $query);
        $request = $request->withQueryParams($query)->withCookieParams($cookies);

        if (null !== $post) {
            $request = $request->withParsedBody([SiteAccessMiddleware::FIELD => $post]);
        }

        $middleware = array_map($this->get(...), Application::MIDDLEWARE);

        try {
            return new MiddlewareDispatcher($middleware, $this->get(PageHandler::class))->handle($request);
        } finally {
            $this->get(CurrentContext::class)->set(Context::system(Channel::Console));
        }
    }
}
