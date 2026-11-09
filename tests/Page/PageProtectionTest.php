<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Page;

use Doctrine\DBAL\Connection;
use Frootbox\Core\Action\Channel;
use Frootbox\Core\Action\Context;
use Frootbox\Core\Action\CurrentContext;
use Frootbox\Core\Action\InvalidInputException;
use Frootbox\Core\Application;
use Frootbox\Core\Auth\AccessTokens;
use Frootbox\Core\Auth\TokenType;
use Frootbox\Core\Cache\PageCache;
use Frootbox\Core\Config\Environment;
use Frootbox\Core\Extension\Extensions;
use Frootbox\Core\Http\MiddlewareDispatcher;
use Frootbox\Core\Page\CreatePage;
use Frootbox\Core\Page\DeletePage;
use Frootbox\Core\Page\EnterPage;
use Frootbox\Core\Page\PageHandler;
use Frootbox\Core\Page\PageOutline;
use Frootbox\Core\Page\PagePasswords;
use Frootbox\Core\Page\PageProtections;
use Frootbox\Core\Page\ProtectPage;
use Frootbox\Core\Page\PublishPages;
use Frootbox\Core\Page\Reach;
use Frootbox\Core\Page\ShowPage;
use Frootbox\Core\Page\UnprotectPage;
use Frootbox\Core\Page\UnpublishPage;
use Frootbox\Core\Preview\CreatePreview;
use Frootbox\Core\Preview\PreviewAccess;
use Frootbox\Core\Tests\Support\FixtureSite;
use Frootbox\Core\Tests\Support\NotesTestCase;
use Frootbox\Core\User\CreateUser;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Http\Message\ResponseInterface;

#[CoversClass(ProtectPage::class)]
#[CoversClass(UnprotectPage::class)]
#[CoversClass(EnterPage::class)]
#[CoversClass(PagePasswords::class)]
#[CoversClass(PageProtections::class)]
#[CoversClass(PageHandler::class)]
final class PageProtectionTest extends NotesTestCase
{
    private string $start;

    private string $courses;

    private string $dates;

    protected function setUp(): void
    {
        parent::setUp();

        $this->start = $this->page('Start');
        $this->courses = $this->page('Kurse', $this->start);
        $this->dates = $this->page('Termine', $this->courses);
        $this->runner()->run(new PublishPages([$this->start, $this->courses, $this->dates]));
    }

    public function testLetsInOnlyWhoKnowsThePassword(): void
    {
        self::assertSame('miss', $this->deliver('/kurse')->getHeaderLine(PageCache::HEADER));
        self::assertSame('hit', $this->deliver('/kurse')->getHeaderLine(PageCache::HEADER));

        $details = $this->runner()->run(new ProtectPage($this->courses, 'kaffeebohne'));
        self::assertSame(Reach::Page, $details->protected);
        self::assertNotNull($details->protectedSince);
        self::assertSame($this->courses, $details->visibility->protectedBy);

        // Strangers get a form, also where the page was in the cache.
        $closed = $this->deliver('/kurse');
        self::assertSame(401, $closed->getStatusCode());
        self::assertStringContainsString('name="fb-page-password"', (string) $closed->getBody());
        self::assertStringNotContainsString('Kurse', (string) $closed->getBody(), 'Nothing of the page, not even its title.');
        self::assertSame('noindex, nofollow', $closed->getHeaderLine('X-Robots-Tag'));
        self::assertSame(200, $this->deliver('/')->getStatusCode(), 'Other pages stay open.');
        self::assertSame(200, $this->deliver('/kurse/termine')->getStatusCode(), 'Only the page itself.');

        $wrong = $this->deliver('/kurse', post: 'falsch');
        self::assertSame(401, $wrong->getStatusCode());
        self::assertStringContainsString('Das Passwort stimmt nicht.', (string) $wrong->getBody());

        $right = $this->deliver('/kurse?ref=mail', post: 'kaffeebohne');
        self::assertSame(303, $right->getStatusCode());
        self::assertSame('/kurse?ref=mail', $right->getHeaderLine('Location'));
        $name = PagePasswords::cookie($this->courses);
        self::assertMatchesRegularExpression('~^' . $name . '=[^;]+; Path=/; HttpOnly; SameSite=Lax; Max-Age=1209600; Secure$~', $right->getHeaderLine('Set-Cookie'));
        $cookie = rawurldecode(explode(';', explode('=', $right->getHeaderLine('Set-Cookie'), 2)[1])[0]);

        $inside = $this->deliver('/kurse', cookies: [$name => $cookie]);
        self::assertSame(200, $inside->getStatusCode());
        self::assertSame('noindex, nofollow', $inside->getHeaderLine('X-Robots-Tag'));
        self::assertSame('private, no-store', $inside->getHeaderLine('Cache-Control'));
        self::assertSame('', $inside->getHeaderLine(PageCache::HEADER), 'Never in the page cache.');
        self::assertStringContainsString('<meta name="robots" content="noindex">', (string) $inside->getBody());
        self::assertSame(401, $this->deliver('/kurse', cookies: [$name => $cookie . 'x'])->getStatusCode());
        self::assertSame(401, $this->deliver('/kurse', cookies: [PagePasswords::cookie($this->dates) => $cookie])->getStatusCode(), 'The cookie of one page only.');
        self::assertSame(303, $this->deliver('/kurse', post: 'egal', cookies: [$name => $cookie])->getStatusCode(), 'Sent again from inside: back to the page.');

        // A new password locks out every browser let in before.
        $this->runner()->run(new ProtectPage($this->courses, 'neue-bohne'));
        self::assertSame(401, $this->deliver('/kurse', cookies: [$name => $cookie])->getStatusCode());

        $this->runner()->run(new UnprotectPage($this->courses));
        $open = $this->deliver('/kurse');
        self::assertSame(200, $open->getStatusCode());
        self::assertSame('', $open->getHeaderLine('X-Robots-Tag'));
        self::assertSame(405, $this->deliver('/kurse', post: 'kaffeebohne')->getStatusCode(), 'No password, no POST.');
    }

    public function testTheNearestPasswordOfABranchApplies(): void
    {
        $this->runner()->run(new ProtectPage($this->courses, 'kaffeebohne', branch: true));
        self::assertSame(401, $this->deliver('/kurse/termine')->getStatusCode());
        self::assertSame($this->courses, $this->runner()->ask(new ShowPage($this->dates))->visibility->protectedBy);

        $right = $this->deliver('/kurse/termine', post: 'kaffeebohne');
        self::assertSame(303, $right->getStatusCode());
        self::assertStringStartsWith(PagePasswords::cookie($this->courses) . '=', $right->getHeaderLine('Set-Cookie'), 'The cookie of the page the password comes from.');
        $cookies = [PagePasswords::cookie($this->courses) => rawurldecode(explode(';', explode('=', $right->getHeaderLine('Set-Cookie'), 2)[1])[0])];
        self::assertSame(200, $this->deliver('/kurse', cookies: $cookies)->getStatusCode());
        self::assertSame(200, $this->deliver('/kurse/termine', cookies: $cookies)->getStatusCode());

        // A password of its own below wins.
        $this->runner()->run(new ProtectPage($this->dates, 'eigene-bohne'));
        self::assertSame(401, $this->deliver('/kurse/termine', cookies: $cookies)->getStatusCode());
        self::assertSame(303, $this->deliver('/kurse/termine', post: 'eigene-bohne')->getStatusCode());
    }

    public function testLetsInUsersAndPreviews(): void
    {
        $this->runner()->run(new ProtectPage($this->courses, 'kaffeebohne'));
        $user = $this->runner()->run(new CreateUser('editor@example.org', 'Erika', roles: ['editor']), Context::system())->user;
        $token = $this->get(AccessTokens::class)->issue($user->id, TokenType::Api)->token;

        self::assertSame(200, $this->deliver('/kurse', headers: ['Authorization' => 'Bearer ' . $token])->getStatusCode());

        $link = $this->runner()->run(new CreatePreview());
        parse_str((string) parse_url((string) $link->path, \PHP_URL_QUERY), $query);
        self::assertIsString($query['fb-preview'] ?? null);
        self::assertSame(200, $this->deliver('/kurse', cookies: [PreviewAccess::COOKIE => $query['fb-preview']])->getStatusCode());
    }

    public function testStaysThroughOfflineAndGoesWithThePage(): void
    {
        $draft = $this->page('Neu', $this->start);
        $details = $this->runner()->run(new ProtectPage($draft, 'kaffeebohne', branch: true));
        self::assertSame(Reach::Branch, $details->protected, 'Also before it is published.');

        $this->runner()->run(new ProtectPage($this->courses, 'kaffeebohne'));
        $this->runner()->run(new UnpublishPage($this->courses));
        $this->runner()->run(new PublishPages([$this->courses]));
        self::assertSame(401, $this->deliver('/kurse')->getStatusCode());

        $this->runner()->run(new DeletePage($draft));
        self::assertNull($this->get(PageProtections::class)->of($draft));
        self::assertNull($this->get(PageOutline::class)->protectedBy($draft));
    }

    public function testDeliversPagesBeforeTheMigration(): void
    {
        // Uploaded, but not migrated yet (decision 0327).
        $this->get(Connection::class)->createSchemaManager()->dropTable(PageProtections::TABLE);

        self::assertSame(200, $this->deliver('/kurse')->getStatusCode());
        self::assertSame(Reach::None, $this->runner()->ask(new ShowPage($this->courses))->protected);
    }

    public function testThrottlesGuessing(): void
    {
        $this->runner()->run(new ProtectPage($this->courses, 'kaffeebohne'));

        for ($i = 0; $i < 5; ++$i) {
            $this->deliver('/kurse', post: 'falsch-' . $i);
        }

        $blocked = $this->deliver('/kurse', post: 'kaffeebohne');
        self::assertSame(401, $blocked->getStatusCode());
        self::assertStringContainsString('Zu viele Versuche', (string) $blocked->getBody());
    }

    public function testChecksThePassword(): void
    {
        $this->expectException(InvalidInputException::class);
        $this->runner()->run(new ProtectPage($this->courses, 'kurz'));
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

    private function page(string $title, ?string $parent = null): string
    {
        return $this->runner()->run(new CreatePage('acme/base/Page', ['de' => ['title' => $title, 'content' => ['fields' => ['heading' => $title]]]], $parent))->id;
    }

    /**
     * @param array<string, string> $cookies
     * @param array<string, string> $headers
     */
    private function deliver(string $path, ?string $post = null, array $cookies = [], array $headers = []): ResponseInterface
    {
        $this->get(PageOutline::class)->clear();
        $request = new ServerRequest(null === $post ? 'GET' : 'POST', 'https://example.org' . $path, $headers, serverParams: ['REMOTE_ADDR' => '203.0.113.9']);
        parse_str((string) parse_url($path, \PHP_URL_QUERY), $query);
        $request = $request->withQueryParams($query)->withCookieParams($cookies);

        if (null !== $post) {
            $request = $request->withParsedBody([PagePasswords::FIELD => $post]);
        }

        $middleware = array_map($this->get(...), Application::MIDDLEWARE);

        try {
            return new MiddlewareDispatcher($middleware, $this->get(PageHandler::class))->handle($request);
        } finally {
            $this->get(CurrentContext::class)->set(Context::system(Channel::Console));
        }
    }
}
