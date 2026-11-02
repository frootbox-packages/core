<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Preview;

use DateTimeImmutable;
use Frootbox\Core\Action\AccessDeniedException;
use Frootbox\Core\Action\Channel;
use Frootbox\Core\Action\ConflictException;
use Frootbox\Core\Action\Context;
use Frootbox\Core\Action\CurrentContext;
use Frootbox\Core\Action\InvalidInputException;
use Frootbox\Core\Application;
use Frootbox\Core\Auth\Actor;
use Frootbox\Core\Config\Environment;
use Frootbox\Core\Extension\Extensions;
use Frootbox\Core\Http\MiddlewareDispatcher;
use Frootbox\Core\Page\CreatePage;
use Frootbox\Core\Page\PageHandler;
use Frootbox\Core\Page\PublishPages;
use Frootbox\Core\Page\UpdatePage;
use Frootbox\Core\Preview\CreatePreview;
use Frootbox\Core\Preview\ListPreviews;
use Frootbox\Core\Preview\OpenPreview;
use Frootbox\Core\Preview\PreviewAccess;
use Frootbox\Core\Preview\PreviewEditor;
use Frootbox\Core\Preview\PreviewLinks;
use Frootbox\Core\Preview\PreviewStatus;
use Frootbox\Core\Preview\PreviewSummary;
use Frootbox\Core\Preview\PreviewTokens;
use Frootbox\Core\Preview\PrunePreviews;
use Frootbox\Core\Preview\RevokePreview;
use Frootbox\Core\Preview\UpdatePreview;
use Frootbox\Core\Site\SiteAccessMiddleware;
use Frootbox\Core\Tests\Support\FixtureSite;
use Frootbox\Core\Tests\Support\NotesTestCase;
use Frootbox\Core\User\AssignRole;
use Frootbox\Core\User\CreateUser;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Http\Message\ResponseInterface;

#[CoversClass(PreviewEditor::class)]
#[CoversClass(PreviewTokens::class)]
#[CoversClass(PreviewLinks::class)]
#[CoversClass(CreatePreview::class)]
#[CoversClass(UpdatePreview::class)]
#[CoversClass(RevokePreview::class)]
#[CoversClass(OpenPreview::class)]
#[CoversClass(ListPreviews::class)]
#[CoversClass(PrunePreviews::class)]
#[CoversClass(SiteAccessMiddleware::class)]
#[CoversClass(PageHandler::class)]
final class PreviewLinksTest extends NotesTestCase
{
    private string $start;

    private Context $editor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->start = $this->createPage(null, 'Start', 'Willkommen');
        $this->runner()->run(new PublishPages([$this->start]));
        $user = $this->runner()->run(new CreateUser('editor@example.org', 'Erika Editor', roles: ['editor']), Context::system())->user;
        $this->editor = new Context(Actor::user($user->id, $user->name), Channel::Web);
    }

    public function testCreatesALinkForTheWholeWebsite(): void
    {
        $link = $this->create('für Frau Müller');

        self::assertSame('für Frau Müller', $link->note);
        self::assertSame(PreviewStatus::Valid, $link->status);
        self::assertSame('Erika Editor', $link->createdBy);
        self::assertMatchesRegularExpression('~^/\?fb-preview=[A-Za-z0-9_-]{43}$~', (string) $link->path);
        self::assertSame('https://example.org' . $link->path, $link->url);
        self::assertEqualsWithDelta(new DateTimeImmutable('+14 days')->getTimestamp(), $link->expiresAt->getTimestamp(), 5, '14 days by default.');

        $stored = $this->connection()->fetchAssociative('SELECT token, token_hash FROM preview_links');
        self::assertIsArray($stored);
        self::assertIsString($stored['token']);
        self::assertStringNotContainsString(self::token($link), $stored['token'], 'The token is kept encrypted.');
        self::assertSame(hash('sha256', self::token($link)), $stored['token_hash']);
        self::assertSame([$link->id], array_map(static fn(PreviewSummary $summary): string => $summary->id, $this->runner()->ask(new ListPreviews(), $this->editor)));
    }

    public function testLeadsToAPageAndChecksTheEnd(): void
    {
        $about = $this->createPage($this->start, 'Über uns', 'Wer wir sind');
        self::assertStringStartsWith('/ueber-uns?fb-preview=', (string) $this->runner()->run(new CreatePreview(pageId: $about), $this->editor)->path);

        foreach ([new DateTimeImmutable('-1 hour'), new DateTimeImmutable('+91 days')] as $expiresAt) {
            try {
                $this->runner()->run(new CreatePreview(expiresAt: $expiresAt), $this->editor);
                self::fail('The end was accepted.');
            } catch (InvalidInputException $error) {
                self::assertArrayHasKey('/expiresAt', $error->errors);
            }
        }
    }

    public function testLeadsAnAgencyToTheDesignPage(): void
    {
        // Decision 0210: the link opens /_design and shows the whole draft as any other.
        $link = $this->runner()->run(new CreatePreview('Agentur', design: true), $this->editor);

        self::assertTrue($link->design);
        self::assertMatchesRegularExpression('~^/_design\?fb-preview=[A-Za-z0-9_-]{43}$~', (string) $link->path);
        self::assertTrue($this->runner()->ask(new ListPreviews(), $this->editor)[0]->design);

        try {
            $this->runner()->run(new CreatePreview(pageId: $this->start, design: true), $this->editor);
            self::fail('A link to a page and the design page was accepted.');
        } catch (InvalidInputException $error) {
            self::assertArrayHasKey('/design', $error->errors);
        }
    }

    public function testShowsTheDraftToTheCustomerOnly(): void
    {
        $news = $this->createPage($this->start, 'Neuigkeiten', 'Bald online');
        $this->runner()->run(new UpdatePage($this->start, ['de' => ['content' => ['fields' => ['heading' => 'Willkommen im Entwurf']]]]));
        $link = $this->create();

        // Opening keeps the token in a cookie and leads to the address without it.
        $opened = $this->deliver('/?fb-preview=' . self::token($link) . '&utm=mail');
        self::assertSame(303, $opened->getStatusCode());
        self::assertSame('/?utm=mail', $opened->getHeaderLine('Location'));
        self::assertStringContainsString('frootbox_preview=' . self::token($link), $opened->getHeaderLine('Set-Cookie'));
        self::assertStringContainsString('HttpOnly', $opened->getHeaderLine('Set-Cookie'));
        self::assertStringContainsString('Secure', $opened->getHeaderLine('Set-Cookie'));
        $summary = $this->runner()->ask(new ListPreviews(), $this->editor)[0];
        self::assertSame(1, $summary->opens);
        self::assertNotNull($summary->lastOpenedAt);

        $start = $this->deliver('/', self::token($link));
        self::assertSame(200, $start->getStatusCode());
        self::assertStringContainsString('Willkommen im Entwurf', (string) $start->getBody());
        $body = (string) $start->getBody();

        // Read only: light marks to comment on, nothing for editing (decision 0156).
        self::assertStringContainsString('data-fb-field="heading" data-fb-label=', $body);

        foreach (['<fb-field', '<fb-area', 'data-fb-type', 'data-fb-value', 'data-fb-placeholder', 'tabindex', 'fb-editor-bar'] as $edit) {
            self::assertStringNotContainsString($edit, $body);
        }
        self::assertSame('private, no-store', $start->getHeaderLine('Cache-Control'));
        self::assertSame('noindex, nofollow', $start->getHeaderLine('X-Robots-Tag'));
        self::assertSame('same-origin', $start->getHeaderLine('Referrer-Policy'));

        // A page never published, at its future address.
        self::assertStringContainsString('Bald online', (string) $this->deliver('/neuigkeiten', self::token($link))->getBody());

        // Guests still get the published website, and the cache holds no draft.
        $guest = $this->deliver('/');
        self::assertStringContainsString('Willkommen', (string) $guest->getBody());
        self::assertStringNotContainsString('Entwurf', (string) $guest->getBody());
        self::assertStringNotContainsString('Entwurf', (string) $this->deliver('/')->getBody());
        self::assertSame(404, $this->deliver('/neuigkeiten')->getStatusCode());
        self::assertNotEmpty($news);
    }

    public function testARevokedLinkEndsAtOnce(): void
    {
        $link = $this->create();
        self::assertSame(200, $this->deliver('/', self::token($link))->getStatusCode());

        $this->runner()->run(new RevokePreview($link->id), $this->editor);
        $gone = $this->deliver('/', self::token($link));
        self::assertSame(410, $gone->getStatusCode());
        self::assertStringContainsString('Vorschaulink', (string) $gone->getBody());
        self::assertStringContainsString('frootbox_preview=;', $gone->getHeaderLine('Set-Cookie'), 'The cookie is forgotten.');
        self::assertSame(410, $this->deliver('/?fb-preview=' . self::token($link))->getStatusCode());
        self::assertSame(410, $this->deliver('/?fb-preview=unknown')->getStatusCode());

        // Routes ignore the old cookie.
        self::assertNotSame(410, $this->deliver('/api/v1/openapi.json', self::token($link))->getStatusCode());

        try {
            $this->runner()->run(new UpdatePreview($link->id, expiresAt: new DateTimeImmutable('+3 days')), $this->editor);
            self::fail('A revoked link was extended.');
        } catch (ConflictException) {
        }
    }

    public function testExtendsAnExpiredLink(): void
    {
        $link = $this->create();
        $this->connection()->update('preview_links', ['expires_at' => new DateTimeImmutable('-1 day')->format('Y-m-d H:i:s')], ['id' => $link->id]);
        self::assertSame(410, $this->deliver('/', self::token($link))->getStatusCode());

        $extended = $this->runner()->run(new UpdatePreview($link->id, note: 'Verlängert', expiresAt: new DateTimeImmutable('+7 days')), $this->editor);
        self::assertSame(PreviewStatus::Valid, $extended->status);
        self::assertSame('Verlängert', $extended->note);
        self::assertSame(200, $this->deliver('/', self::token($link))->getStatusCode());
        self::assertSame('Verlängert', $this->runner()->run(new UpdatePreview($link->id), $this->editor)->note, 'Left out, the note stays.');
        self::assertNull($this->runner()->run(new UpdatePreview($link->id, note: ''), $this->editor)->note, 'Empty, it goes.');
    }

    public function testEndsThePreviewOnRequest(): void
    {
        $link = $this->create();
        $ended = $this->deliver('/?fb-preview=0', self::token($link));

        self::assertSame(303, $ended->getStatusCode());
        self::assertSame('/', $ended->getHeaderLine('Location'));
        self::assertStringContainsString('frootbox_preview=;', $ended->getHeaderLine('Set-Cookie'));
    }

    public function testOnlyRolesThatSeeAllPagesCreateLinks(): void
    {
        $user = $this->runner()->run(new CreateUser('branch@example.org', 'Zweig'), Context::system())->user;
        $this->runner()->run(new AssignRole($user->id, 'editor', 'page:' . $this->start), Context::system());

        $this->expectException(AccessDeniedException::class);
        $this->runner()->run(new CreatePreview(), new Context(Actor::user($user->id, $user->name), Channel::Web));
    }

    public function testDeletesLinksAMonthAfterTheyEnded(): void
    {
        $old = $this->create();
        $recent = $this->create();
        $valid = $this->create();

        // Created within the same second, the order would rest on random ULIDs.
        foreach ([$old, $recent, $valid] as $minutes => $link) {
            $this->connection()->update('preview_links', ['created_at' => new DateTimeImmutable(\sprintf('-%d minutes', 3 - $minutes))->format('Y-m-d H:i:s')], ['id' => $link->id]);
        }

        $this->connection()->update('preview_links', ['expires_at' => new DateTimeImmutable('-31 days')->format('Y-m-d H:i:s')], ['id' => $old->id]);
        $this->connection()->update('preview_links', ['revoked_at' => new DateTimeImmutable('-29 days')->format('Y-m-d H:i:s')], ['id' => $recent->id]);

        self::assertSame(1, $this->runner()->run(new PrunePreviews()));
        self::assertSame([$valid->id, $recent->id], array_map(static fn(PreviewSummary $summary): string => $summary->id, $this->runner()->ask(new ListPreviews(), $this->editor)));
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

    private function create(?string $note = null): PreviewSummary
    {
        return $this->runner()->run(new CreatePreview($note), $this->editor);
    }

    private static function token(PreviewSummary $link): string
    {
        parse_str((string) parse_url((string) $link->path, \PHP_URL_QUERY), $query);
        $token = $query['fb-preview'] ?? null;

        return \is_string($token) ? $token : self::fail('The link has no token.');
    }

    private function createPage(?string $parentId, string $title, string $heading): string
    {
        return $this->runner()->run(new CreatePage('acme/base/Page', ['de' => ['title' => $title, 'content' => ['fields' => ['heading' => $heading]]]], $parentId))->id;
    }

    private function deliver(string $path, ?string $preview = null): ResponseInterface
    {
        $request = new ServerRequest('GET', 'https://example.org' . $path);
        parse_str((string) parse_url($path, \PHP_URL_QUERY), $query);
        $request = $request->withQueryParams($query);
        $middleware = array_map($this->get(...), Application::MIDDLEWARE);

        try {
            return new MiddlewareDispatcher($middleware, $this->get(PageHandler::class))->handle(null === $preview ? $request : $request->withCookieParams([PreviewAccess::COOKIE => $preview]));
        } finally {
            $this->get(CurrentContext::class)->set(Context::system());
        }
    }
}
