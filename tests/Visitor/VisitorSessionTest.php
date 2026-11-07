<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Visitor;

use Closure;
use DateTimeImmutable;
use Frootbox\Core\Action\Channel;
use Frootbox\Core\Action\Context;
use Frootbox\Core\Action\CurrentContext;
use Frootbox\Core\Application;
use Frootbox\Core\Cache\PageCache;
use Frootbox\Core\Config\Environment;
use Frootbox\Core\Extension\Extensions;
use Frootbox\Core\Http\CallableRequestHandler;
use Frootbox\Core\Http\MiddlewareDispatcher;
use Frootbox\Core\Page\CreatePage;
use Frootbox\Core\Page\PageHandler;
use Frootbox\Core\Page\PublishPages;
use Frootbox\Core\Schedule\ScheduleRan;
use Frootbox\Core\Tests\Support\FixtureSite;
use Frootbox\Core\Tests\Support\NotesTestCase;
use Frootbox\Core\Visitor\PruneVisitorSessions;
use Frootbox\Core\Visitor\VisitorSession;
use Frootbox\Core\Visitor\VisitorSessionMiddleware;
use Frootbox\Core\Visitor\VisitorSessions;
use Frootbox\Database\Values;
use LogicException;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

#[CoversClass(VisitorSession::class)]
#[CoversClass(VisitorSessions::class)]
#[CoversClass(VisitorSessionMiddleware::class)]
#[CoversClass(PruneVisitorSessions::class)]
final class VisitorSessionTest extends NotesTestCase
{
    private const string PACKAGE = 'acme/shop';

    public function testACookieComesOnlyWithTheFirstValue(): void
    {
        $look = $this->request(static fn(VisitorSession $session): mixed => $session->get(self::PACKAGE, 'cart'));
        self::assertSame([], $look->getHeader('Set-Cookie'));
        self::assertSame(0, $this->sessions());

        $add = $this->request(static fn(VisitorSession $session) => $session->set(self::PACKAGE, 'cart', ['p1' => 2]));
        $header = $add->getHeaderLine('Set-Cookie');
        self::assertMatchesRegularExpression('/^fb_visitor=[0-9a-f]{64}; Path=\/; HttpOnly; SameSite=Lax; Max-Age=2592000; Secure$/', $header);
        self::assertSame(1, $this->sessions());

        // Only the hash of the cookie is stored.
        $cookie = self::cookie($add);
        self::assertSame(hash('sha256', $cookie), $this->connection()->fetchOne('SELECT id FROM visitor_sessions'));

        $read = $this->request(static fn(VisitorSession $session): mixed => $session->get(self::PACKAGE, 'cart'), $cookie);
        self::assertSame(['p1' => 2], json_decode($read->getHeaderLine('X-Value'), true));
        // Seen just now: nothing to write, no new cookie.
        self::assertSame([], $read->getHeader('Set-Cookie'));
    }

    public function testValuesAreKeptPerPackage(): void
    {
        $cookie = self::cookie($this->request(static function (VisitorSession $session): void {
            $session->set(self::PACKAGE, 'cart', ['p1' => 1]);
            $session->set('acme/wishlist', 'items', ['p2']);
        }));

        $this->request(static fn(VisitorSession $session) => $session->clear('acme/wishlist'), $cookie);

        $all = $this->request(static fn(VisitorSession $session): array => [$session->all(self::PACKAGE), $session->all('acme/wishlist')], $cookie);
        self::assertSame([['cart' => ['p1' => 1]], []], json_decode($all->getHeaderLine('X-Value'), true));
    }

    public function testAnEmptySessionEndsAndTheCookieGoes(): void
    {
        $cookie = self::cookie($this->request(static fn(VisitorSession $session) => $session->set(self::PACKAGE, 'cart', ['p1' => 1])));

        $removed = $this->request(static fn(VisitorSession $session) => $session->remove(self::PACKAGE, 'cart'), $cookie);
        self::assertSame('fb_visitor=; Path=/; HttpOnly; SameSite=Lax; Max-Age=0; Secure', $removed->getHeaderLine('Set-Cookie'));
        self::assertSame(0, $this->sessions());
    }

    public function testAnUnknownOrExpiredCookieIsForgotten(): void
    {
        $unknown = $this->request(static fn(VisitorSession $session): bool => $session->active(), str_repeat('a', 64));
        self::assertSame('false', $unknown->getHeaderLine('X-Value'));
        self::assertStringStartsWith('fb_visitor=; ', $unknown->getHeaderLine('Set-Cookie'));

        $broken = $this->request(static fn(VisitorSession $session): bool => $session->active(), 'nonsense');
        self::assertStringStartsWith('fb_visitor=; ', $broken->getHeaderLine('Set-Cookie'));

        $cookie = self::cookie($this->request(static fn(VisitorSession $session) => $session->set(self::PACKAGE, 'cart', [])));
        $this->connection()->executeStatement('UPDATE visitor_sessions SET expires_at = ?', [new DateTimeImmutable('-1 minute')->format(Values::DATE_FORMAT)]);
        $expired = $this->request(static fn(VisitorSession $session): bool => $session->active(), $cookie);
        self::assertSame('false', $expired->getHeaderLine('X-Value'));
        self::assertStringStartsWith('fb_visitor=; ', $expired->getHeaderLine('Set-Cookie'));
    }

    public function testRegenerateGivesANewCookieWithTheSameValues(): void
    {
        $old = self::cookie($this->request(static fn(VisitorSession $session) => $session->set(self::PACKAGE, 'cart', ['p1' => 3])));

        $new = self::cookie($this->request(static fn(VisitorSession $session) => $session->regenerate(), $old));
        self::assertNotSame($old, $new);
        self::assertSame(1, $this->sessions());

        self::assertSame('false', $this->request(static fn(VisitorSession $session): bool => $session->active(), $old)->getHeaderLine('X-Value'));
        self::assertSame('{"p1":3}', $this->request(static fn(VisitorSession $session): mixed => $session->get(self::PACKAGE, 'cart'), $new)->getHeaderLine('X-Value'));
    }

    public function testTheLastRequestIsWrittenAtMostEveryFiveMinutes(): void
    {
        $cookie = self::cookie($this->request(static fn(VisitorSession $session) => $session->set(self::PACKAGE, 'cart', [])));
        $past = new DateTimeImmutable('-10 minutes')->format(Values::DATE_FORMAT);
        $this->connection()->executeStatement('UPDATE visitor_sessions SET seen_at = ?, expires_at = ?', [$past, new DateTimeImmutable('+1 day')->format(Values::DATE_FORMAT)]);

        $touched = $this->request(static fn(VisitorSession $session): bool => $session->active(), $cookie);
        self::assertStringStartsWith('fb_visitor=' . $cookie, $touched->getHeaderLine('Set-Cookie'));
        self::assertGreaterThan($past, $this->connection()->fetchOne('SELECT seen_at FROM visitor_sessions'));
        self::assertGreaterThan(new DateTimeImmutable('+29 days')->format(Values::DATE_FORMAT), $this->connection()->fetchOne('SELECT expires_at FROM visitor_sessions'));
    }

    public function testNoSessionWithoutABrowser(): void
    {
        $session = $this->get(VisitorSession::class);
        self::assertFalse($session->available());

        // The REST API and MCP come with an access token.
        $api = $this->request(static fn(VisitorSession $session): bool => $session->available(), headers: ['Authorization' => 'Bearer fbx_x']);
        self::assertSame('false', $api->getHeaderLine('X-Value'));

        $this->expectException(LogicException::class);
        $session->set(self::PACKAGE, 'cart', []);
    }

    public function testExpiredSessionsArePrunedOnSchedule(): void
    {
        $this->request(static fn(VisitorSession $session) => $session->set(self::PACKAGE, 'cart', []));
        $this->request(static fn(VisitorSession $session) => $session->set(self::PACKAGE, 'cart', []));
        // MySQL cannot update a table it selects from in a subquery.
        $first = $this->connection()->fetchOne('SELECT MIN(id) FROM visitor_sessions');
        $this->connection()->executeStatement('UPDATE visitor_sessions SET expires_at = ? WHERE id = ?', [new DateTimeImmutable('-1 day')->format(Values::DATE_FORMAT), $first]);

        $prune = $this->get(PruneVisitorSessions::class);
        $prune(new ScheduleRan(new DateTimeImmutable()));
        self::assertSame(1, $this->sessions());
    }

    public function testAVisitorWithASessionGetsNothingFromThePageCache(): void
    {
        $start = $this->runner()->run(new CreatePage('acme/base/Page', ['de' => ['title' => 'Start', 'content' => ['fields' => ['heading' => 'Willkommen']]]]))->id;
        $this->runner()->run(new PublishPages([$start]));

        self::assertSame('miss', $this->deliver('/')->getHeaderLine(PageCache::HEADER));
        self::assertSame('hit', $this->deliver('/')->getHeaderLine(PageCache::HEADER));

        $cookie = self::cookie($this->request(static fn(VisitorSession $session) => $session->set(self::PACKAGE, 'cart', ['p1' => 1])));
        $page = $this->deliver('/', $cookie);
        self::assertSame(200, $page->getStatusCode());
        self::assertSame('', $page->getHeaderLine(PageCache::HEADER));
        self::assertSame('private, no-store', $page->getHeaderLine('Cache-Control'));
    }

    protected function extensions(): Extensions
    {
        return FixtureSite::extensions('base', 'site');
    }

    protected function config(): array
    {
        return ['app' => ['name' => 'Testsite', 'url' => 'https://example.org'], 'theme' => 'acme/site', 'languages' => ['de' => ['title' => 'Deutsch']]];
    }

    protected function environment(): Environment
    {
        // Live, so the page cache is on.
        return 'testAVisitorWithASessionGetsNothingFromThePageCache' === $this->name() ? Environment::Production : Environment::Development;
    }

    /**
     * Runs a request through the middleware; the value of the callback
     * comes back as JSON in the header X-Value.
     *
     * @param Closure(VisitorSession): mixed $callback
     * @param array<string, string> $headers
     */
    private function request(Closure $callback, ?string $cookie = null, array $headers = []): ResponseInterface
    {
        $request = new ServerRequest('POST', 'https://example.org/_test', $headers)->withCookieParams(null === $cookie ? [] : [VisitorSession::COOKIE => $cookie]);
        $session = $this->get(VisitorSession::class);
        $handler = new CallableRequestHandler(static fn(ServerRequestInterface $request): ResponseInterface => new Response(200, ['X-Value' => json_encode($callback($session), \JSON_THROW_ON_ERROR)]));

        return new MiddlewareDispatcher([$this->get(VisitorSessionMiddleware::class)], $handler)->handle($request);
    }

    private function deliver(string $path, ?string $cookie = null): ResponseInterface
    {
        $request = new ServerRequest('GET', 'https://example.org' . $path)->withCookieParams(null === $cookie ? [] : [VisitorSession::COOKIE => $cookie]);
        $middleware = array_map($this->get(...), Application::MIDDLEWARE);

        try {
            return new MiddlewareDispatcher($middleware, $this->get(PageHandler::class))->handle($request);
        } finally {
            $this->get(CurrentContext::class)->set(Context::system(Channel::Console));
        }
    }

    private function sessions(): int
    {
        $count = $this->connection()->fetchOne('SELECT COUNT(*) FROM visitor_sessions');

        return is_numeric($count) ? (int) $count : -1;
    }

    private static function cookie(ResponseInterface $response): string
    {
        self::assertSame(1, preg_match('/^fb_visitor=([0-9a-f]{64});/', $response->getHeaderLine('Set-Cookie'), $match));

        return $match[1];
    }
}
