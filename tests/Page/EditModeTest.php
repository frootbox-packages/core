<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Page;

use DateTimeImmutable;
use Frootbox\Core\Auth\AccessToken;
use Frootbox\Core\Auth\Actor;
use Frootbox\Core\Auth\Authentication;
use Frootbox\Core\Auth\TokenType;
use Frootbox\Core\Cache\PageCache;
use Frootbox\Core\Config\Environment;
use Frootbox\Core\Extension\Extensions;
use Frootbox\Core\Http\Exception\HttpException;
use Frootbox\Core\Language\Languages;
use Frootbox\Core\Navigation\MenuItem;
use Frootbox\Core\Navigation\Menus;
use Frootbox\Core\Navigation\PublishNavigations;
use Frootbox\Core\Navigation\UpdateNavigation;
use Frootbox\Core\Page\CreatePage;
use Frootbox\Core\Page\EditMode;
use Frootbox\Core\Page\LockPage;
use Frootbox\Core\Page\MovePage;
use Frootbox\Core\Page\PageHandler;
use Frootbox\Core\Page\PageOutline;
use Frootbox\Core\Page\PageResolver;
use Frootbox\Core\Page\PublishPages;
use Frootbox\Core\Page\UpdatePage;
use Frootbox\Core\Preview\PreviewAccess;
use Frootbox\Core\Tests\Support\FixtureSite;
use Frootbox\Core\Tests\Support\NotesTestCase;
use Frootbox\Core\Url\DraftAddresses;
use Frootbox\Core\Url\PagePaths;
use Frootbox\Core\Url\Target;
use Frootbox\Core\Url\Urls;
use Frootbox\Core\User\AssignRole;
use Frootbox\Core\User\CreateUser;
use Frootbox\Core\View\ComponentRenderer;
use Frootbox\Core\View\Delivery;
use Frootbox\Core\View\EditMarkup;
use Frootbox\Core\View\ListValue;
use Frootbox\Core\View\Scope;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Http\Message\ResponseInterface;

#[CoversClass(EditMode::class)]
#[CoversClass(EditMarkup::class)]
#[CoversClass(DraftAddresses::class)]
#[CoversClass(PagePaths::class)]
#[CoversClass(PageHandler::class)]
#[CoversClass(PageResolver::class)]
#[CoversClass(PageOutline::class)]
#[CoversClass(Urls::class)]
#[CoversClass(Menus::class)]
#[CoversClass(ComponentRenderer::class)]
#[CoversClass(Scope::class)]
#[CoversClass(ListValue::class)]
final class EditModeTest extends NotesTestCase
{
    private string $start;

    private string $about;

    private string $contact;

    private Actor $admin;

    private Actor $editor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->start = $this->createPage(null, 'Start', ['fields' => ['heading' => 'Willkommen'], 'areas' => ['main' => [
            ['block' => 'acme/base/Teaser', 'fields' => ['title' => 'Frisch geröstet']],
            ['block' => 'acme/site/Hero', 'fields' => ['slides' => [['title' => 'Eins'], ['title' => 'Zwei']]]],
        ]]]);
        $this->about = $this->createPage($this->start, 'Über uns');
        $this->contact = $this->createPage($this->start, 'Kontakt');
        $this->runner()->run(new PublishPages([$this->start, $this->about, $this->contact]));

        $admin = $this->runner()->run(new CreateUser('admin@example.org', 'Admin', 'kaffeebohne42'))->user;
        $this->runner()->run(new AssignRole($admin->id, 'admin'));
        $this->admin = Actor::user($admin->id, $admin->name);

        $editor = $this->runner()->run(new CreateUser('jane@example.org', 'Jane', 'kaffeebohne42'))->user;
        $this->runner()->run(new AssignRole($editor->id, 'editor', 'page:' . $this->about));
        $this->editor = Actor::user($editor->id, $editor->name);
    }

    public function testLeavesVisitorsAlone(): void
    {
        $this->runner()->run(new UpdatePage($this->start, ['de' => ['content' => ['fields' => ['heading' => 'Entwurf']]]]));

        $first = $this->request('/', cookie: true);
        $html = (string) $first->getBody();

        self::assertSame('miss', $first->getHeaderLine(PageCache::HEADER));
        self::assertStringContainsString('<h1>Willkommen</h1>', $html, 'The cookie alone does nothing.');
        self::assertStringNotContainsString('fb-', $html);
        self::assertSame('hit', $this->request('/', cookie: true)->getHeaderLine(PageCache::HEADER), 'The cache still hits.');
        self::assertFalse($this->get(EditMode::class)->active());
    }

    public function testNeedsTheSwitchAndThePermission(): void
    {
        $this->runner()->run(new UpdatePage($this->start, ['de' => ['content' => ['fields' => ['heading' => 'Entwurf']]]]));

        self::assertStringContainsString('<h1>Willkommen</h1>', $this->html('/', $this->admin, cookie: false), 'Without the switch the page is as published.');

        $nobody = $this->runner()->run(new CreateUser('joe@example.org', 'Joe', 'kaffeebohne42'))->user;
        self::assertStringContainsString('<h1>Willkommen</h1>', $this->html('/', Actor::user($nobody->id, $nobody->name)), 'Without page.show neither.');
        self::assertFalse($this->get(EditMode::class)->active());

        self::assertStringContainsString('<h1 data-fb-field="heading" data-fb-type="headline" data-fb-label="Heading" tabindex="0" data-fb-value="&quot;Entwurf&quot;">Entwurf</h1>', $this->html('/', $this->admin));
        self::assertTrue($this->get(EditMode::class)->marking());
    }

    public function testShowsTheWholeSiteFromTheDraft(): void
    {
        $new = $this->createPage($this->about, 'Neu im Team');
        $this->runner()->run(new UpdatePage($this->about, ['de' => ['title' => 'Wir', 'slug' => 'wir']]));
        $this->runner()->run(new MovePage($this->contact, $this->about));

        $this->assertNotFound('/wir', 'Visitors know only the published addresses.');
        self::assertStringContainsString('Neu im Team', $this->html('/wir/neu-im-team', $this->admin), 'A page that was never published, at its future address.');
        self::assertStringContainsString('Kontakt', $this->html('/wir/kontakt', $this->admin));

        $urls = $this->get(Urls::class);
        $urls->clear();
        self::assertSame('/wir/neu-im-team', $urls->path(Target::page($new), 'de'));

        $outline = $this->get(PageOutline::class);
        $outline->clear();
        self::assertSame([$this->start, $this->about, $this->contact], $outline->trail($this->contact), 'The tree comes from the draft.');
    }

    public function testShowsNavigationsFromTheDraft(): void
    {
        $this->runner()->run(new UpdateNavigation('main', ['de' => [['type' => 'target', 'target' => 'page:' . $this->about]]]));
        $this->runner()->run(new PublishNavigations(['main']));
        $this->runner()->run(new UpdateNavigation('main', ['de' => [['type' => 'target', 'target' => 'page:' . $this->contact]]]));

        self::assertSame(['Über uns'], $this->menu(false));
        self::assertSame(['Kontakt'], $this->menu(true));
    }

    public function testShowsLockedPagesInTheMode(): void
    {
        $this->runner()->run(new LockPage($this->about));

        try {
            $this->request('/ueber-uns', $this->admin, cookie: false);
            self::fail('The locked page was answered.');
        } catch (HttpException $error) {
            self::assertSame(503, $error->status);
        }

        self::assertStringContainsString('Über uns', $this->html('/ueber-uns', $this->admin));
    }

    public function testMarksBlocksFieldsAndEmptyAreas(): void
    {
        $html = $this->html('/', $this->admin);

        self::assertMatchesRegularExpression('/<article data-fb-block="[0-9a-z]{8}" data-fb-name="acme\/base\/Teaser" data-fb-title="Teaser" data-fb-area="main" class="site-teaser"/', $html);
        self::assertStringContainsString('<h3 data-fb-field="title" data-fb-type="headline"', $html, 'A field alone in its element marks the element.');
        self::assertStringContainsString('<fb-field data-fb-field="text" data-fb-type="richtext" data-fb-label="Text" data-fb-optional data-fb-headings="3" data-fb-heading-flow="{&quot;wanted&quot;:[1,2,3],&quot;last&quot;:0,&quot;floor&quot;:1,&quot;main&quot;:false}" tabindex="0"></fb-field></article>', $html, 'Next to others it keeps the wrapper.');
        self::assertStringContainsString('<li><fb-field data-fb-field="slides/0/title" data-fb-type="headline" data-fb-label="Title" data-fb-placeholder="Slide title" tabindex="0" data-fb-value="&quot;Eins&quot;">Eins</fb-field><fb-field data-fb-field="slides/0/link"', $html);
        self::assertStringContainsString('data-fb-field="slides/1/title"', $html);
        self::assertStringContainsString('<fb-area data-fb-area="main" data-fb-empty></fb-area>', $this->html('/ueber-uns', $this->admin));
    }

    public function testMarksLightlyInAPreview(): void
    {
        $this->get(Urls::class)->clear();
        $this->get(PageOutline::class)->clear();
        $request = new ServerRequest('GET', 'https://example.org/')->withAttribute(PreviewAccess::class, new PreviewAccess('01JPREVIEW0000000000000000', new DateTimeImmutable('+1 day')));
        $html = (string) $this->get(PageHandler::class)->handle($request)->getBody();

        self::assertTrue($this->get(EditMode::class)->anchors());
        self::assertMatchesRegularExpression('/<article data-fb-block="[0-9a-z]{8}" data-fb-name="acme\/base\/Teaser" data-fb-title="Teaser" data-fb-area="main" class="site-teaser"/', $html);
        self::assertStringContainsString('<h3 data-fb-field="title" data-fb-label="Title">Frisch geröstet</h3>', $html, 'A field alone in its element marks the element.');
        self::assertStringContainsString('<li data-fb-field="slides/0/title" data-fb-label="Title">Eins</li>', $html, 'With the position as stored.');
        self::assertStringNotContainsString('data-fb-field="text"', $html, 'An empty field leaves out its element, as for visitors.');

        foreach (['<fb-field', '<fb-area', 'data-fb-type', 'data-fb-value', 'data-fb-placeholder', 'tabindex'] as $edit) {
            self::assertStringNotContainsString($edit, $html);
        }
    }

    public function testMarksOnlyWhatTheUserMayChange(): void
    {
        $outside = $this->html('/', $this->editor);
        self::assertStringContainsString('<h1>Willkommen</h1>', $outside, 'The editor sees the draft but cannot change it.');
        self::assertStringNotContainsString('data-fb-', $outside);
        self::assertTrue($this->get(EditMode::class)->active());
        self::assertFalse($this->get(EditMode::class)->marking());

        self::assertStringContainsString('data-fb-field="heading"', $this->html('/ueber-uns', $this->editor), 'Within the branch.');
    }

    public function testMarksNothingInAFallbackLanguage(): void
    {
        $html = $this->html('/en/ueber-uns', $this->admin);

        self::assertStringContainsString('Über uns', $html, 'The German content stands in.');
        self::assertStringNotContainsString('data-fb-', $html, 'There is no English content to change yet.');
        self::assertTrue($this->get(EditMode::class)->active());
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
        return ['app' => ['name' => 'Testsite', 'url' => 'https://example.org'], 'theme' => 'acme/site', 'languages' => ['de' => ['title' => 'Deutsch'], 'en' => ['title' => 'English']]];
    }

    /**
     * @param array<string, mixed>|null $content
     */
    private function createPage(?string $parentId, string $title, ?array $content = null): string
    {
        return $this->runner()->run(new CreatePage('acme/base/Page', ['de' => ['title' => $title, 'content' => $content ?? ['fields' => ['heading' => $title]]]], $parentId))->id;
    }

    private function request(string $path, ?Actor $actor = null, bool $cookie = true): ResponseInterface
    {
        // Each request starts afresh, as in its own process.
        $this->get(Urls::class)->clear();
        $this->get(PageOutline::class)->clear();

        $request = new ServerRequest('GET', 'https://example.org' . $path);

        if ($cookie) {
            $request = $request->withCookieParams([EditMode::COOKIE => '1']);
        }

        if (null !== $actor) {
            $token = new AccessToken(1, (int) $actor->id, TokenType::Session, null, null, null, new DateTimeImmutable());
            $request = $request->withAttribute(Authentication::class, new Authentication($actor, $token));
        }

        return $this->get(PageHandler::class)->handle($request);
    }

    private function html(string $path, Actor $actor, bool $cookie = true): string
    {
        $response = $this->request($path, $actor, $cookie);
        self::assertSame(200, $response->getStatusCode());

        return (string) $response->getBody();
    }

    private function assertNotFound(string $path, string $message): void
    {
        try {
            $this->request($path);
            self::fail(\sprintf('%s was answered. %s', $path, $message));
        } catch (HttpException $error) {
            self::assertSame(404, $error->status, $message);
        }
    }

    /**
     * @return list<string>
     */
    private function menu(bool $editing): array
    {
        $this->get(EditMode::class)->set($editing);
        $this->get(Urls::class)->clear();
        $this->get(PageOutline::class)->clear();
        $this->get(Delivery::class)->set($this->get(Languages::class)->get('de'), target: Target::page($this->start));

        return array_map(static fn(MenuItem $item): string => $item->label, $this->get(Menus::class)->navigation('main'));
    }
}
