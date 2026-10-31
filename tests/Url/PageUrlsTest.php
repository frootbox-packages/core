<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Url;

use Frootbox\Core\Action\Undo\UndoAction;
use Frootbox\Core\Extension\Extensions;
use Frootbox\Core\Http\Exception\HttpException;
use Frootbox\Core\Page\CreatePage;
use Frootbox\Core\Page\DeletePage;
use Frootbox\Core\Page\MovePage;
use Frootbox\Core\Page\PageHandler;
use Frootbox\Core\Page\PageResolver;
use Frootbox\Core\Page\PublishPages;
use Frootbox\Core\Page\ResolvedPage;
use Frootbox\Core\Page\UpdatePage;
use Frootbox\Core\Tests\Support\FixtureSite;
use Frootbox\Core\Tests\Support\NotesTestCase;
use Frootbox\Core\Url\Address;
use Frootbox\Core\Url\Addresses;
use Frootbox\Core\Url\AddressSync;
use Frootbox\Core\Url\PagePath;
use Frootbox\Core\Url\PagePaths;
use Frootbox\Core\Url\Resolution;
use Frootbox\Core\Url\SyncUrls;
use Frootbox\Core\Url\Target;
use Frootbox\Core\Url\UrlResolver;
use Frootbox\Core\Url\Urls;
use Frootbox\Core\View\Delivery;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Http\Message\ResponseInterface;

#[CoversClass(Target::class)]
#[CoversClass(Address::class)]
#[CoversClass(Addresses::class)]
#[CoversClass(AddressSync::class)]
#[CoversClass(PagePath::class)]
#[CoversClass(PagePaths::class)]
#[CoversClass(Resolution::class)]
#[CoversClass(UrlResolver::class)]
#[CoversClass(Urls::class)]
#[CoversClass(SyncUrls::class)]
#[CoversClass(Delivery::class)]
#[CoversClass(PageResolver::class)]
#[CoversClass(ResolvedPage::class)]
#[CoversClass(PageHandler::class)]
final class PageUrlsTest extends NotesTestCase
{
    private const string LAYOUT = 'acme/base/Page';

    public function testGivesEveryLanguageItsUrls(): void
    {
        $start = $this->createPage(null, ['de' => 'Start', 'en' => 'Home']);
        $about = $this->createPage($start, ['de' => 'Über uns', 'en' => 'About us']);
        $team = $this->createPage($about, ['de' => 'Team'], [['block' => 'acme/base/Teaser', 'fields' => ['title' => 'Unser Team']]]);
        $this->publish($start, $about, $team);

        $html = $this->html('/en/about-us');
        self::assertStringStartsWith("<!doctype html>\n<html lang=\"en\">", $html);
        self::assertStringContainsString('<title>About us – Testsite</title>', $html);
        self::assertStringContainsString('<link rel="canonical" href="https://example.org/en/about-us">', $html);
        self::assertStringContainsString('<link rel="alternate" hreflang="de" href="https://example.org/ueber-uns">', $html);
        self::assertStringContainsString('<link rel="alternate" hreflang="en" href="https://example.org/en/about-us">', $html);
        self::assertStringContainsString('<link rel="alternate" hreflang="x-default" href="https://example.org/ueber-uns">', $html);

        self::assertStringContainsString('<html lang="de">', $this->html('/ueber-uns'));
        self::assertStringContainsString('<title>Testsite</title>', $this->html('/en'));
        $this->assertStatus(404, '/en/ueber-uns');

        // Team has no English translation: English frame, German content, canonical to the German original.
        $fallback = $this->html('/en/about-us/team');
        self::assertStringContainsString('<html lang="en">', $fallback);
        self::assertStringContainsString('<article lang="de" class="site-teaser"><h3>Unser Team</h3>', $fallback);
        self::assertStringContainsString('<link rel="canonical" href="https://example.org/ueber-uns/team">', $fallback);
        self::assertStringNotContainsString('hreflang', $fallback, 'Only one real translation, so no alternates.');
        self::assertStringContainsString('<article class="site-teaser">', $this->html('/ueber-uns/team'), 'Blocks are marked only on a fallback.');

        // French has no fallback: without translation no page.
        $this->assertStatus(404, '/fr');
        $this->assertStatus(404, '/fr/ueber-uns');

        $urls = $this->get(Urls::class);
        self::assertSame('/en/about-us/team', $urls->path(Target::page($team), 'en'));
        self::assertSame('/ueber-uns/team', $urls->path('page:' . $team, 'de'));
        self::assertNull($urls->path(Target::page($team), 'fr'));
        self::assertSame(0, $this->runner()->run(new SyncUrls()), 'Publishing already brought the addresses up to date.');
    }

    public function testRedirectsToWherePagesMoved(): void
    {
        $start = $this->createPage(null, ['de' => 'Start', 'en' => 'Home']);
        $about = $this->createPage($start, ['de' => 'Über uns', 'en' => 'About us']);
        $team = $this->createPage($about, ['de' => 'Team']);
        $this->publish($start, $about, $team);

        $this->runner()->run(new UpdatePage($about, ['de' => ['slug' => 'unternehmen']]));
        $this->publish($about);

        $this->assertRedirect('/unternehmen/team?tab=2', '/ueber-uns/team?tab=2');
        $this->assertRedirect('/unternehmen', '/ueber-uns');
        self::assertSame(200, $this->request('/en/about-us/team')->getStatusCode(), 'The English slug stayed.');

        // Moving again: the oldest URL goes straight to the newest, no chain.
        $this->runner()->run(new MovePage($team, $start));
        $this->publish($team);

        $this->assertRedirect('/team', '/ueber-uns/team');
        $this->assertRedirect('/team', '/unternehmen/team');
        $this->assertRedirect('/en/team', '/en/about-us/team');

        // A new page takes the old path: it wins, the earlier address is gone.
        $new = $this->createPage($start, ['de' => 'Über uns']);
        $this->publish($new);
        self::assertSame(200, $this->request('/ueber-uns')->getStatusCode());
        self::assertNull($this->get(Addresses::class)->earlier('de', 'ueber-uns'));

        // Deleted: 410 at the current and every earlier URL. Undo brings it back.
        $this->runner()->run(new DeletePage($team));
        $this->assertStatus(410, '/team');
        $this->assertStatus(410, '/unternehmen/team');

        $this->runner()->run(new UndoAction($this->lastEntry()->id));
        self::assertSame(200, $this->request('/team')->getStatusCode());
        $this->assertRedirect('/team', '/unternehmen/team');
    }

    public function testUndoingAPublishRestoresTheUrls(): void
    {
        $start = $this->createPage(null, ['de' => 'Start']);
        $about = $this->createPage($start, ['de' => 'Über uns']);
        $this->publish($start, $about);
        $this->runner()->run(new UpdatePage($about, ['de' => ['slug' => 'wir']]));
        $this->publish($about);
        $this->assertRedirect('/wir', '/ueber-uns');

        $this->runner()->run(new UndoAction($this->lastEntry()->id));

        self::assertSame(200, $this->request('/ueber-uns')->getStatusCode());
        $this->assertStatus(404, '/wir');
    }

    public function testOwnSlugsWinOverFallbackSlugs(): void
    {
        $start = $this->createPage(null, ['de' => 'Start', 'en' => 'Home']);
        $german = $this->createPage($start, ['de' => 'Kontakt']);
        $english = $this->createPage($start, ['de' => 'Anfahrt', 'en' => 'Kontakt']);
        $this->publish($start, $german, $english);

        self::assertStringContainsString('<html lang="en">', $this->html('/en/kontakt'));
        self::assertSame('/en/kontakt', $this->get(Urls::class)->path(Target::page($english), 'en'));
        self::assertNull($this->get(Urls::class)->path(Target::page($german), 'en'), 'The fallback slug is taken by an own one.');
    }

    public function testTargetsAreChecked(): void
    {
        self::assertSame('page:01ARZ3NDEKTSV4RRFFQ69G5FAV', (string) Target::parse('page:01ARZ3NDEKTSV4RRFFQ69G5FAV'));
        self::assertSame('acme/shop/product', Target::parse('acme/shop/product:123')->type);
        self::assertFalse(Target::parse('acme/shop/product:123')->isPage());
        self::assertNull(Target::tryParse('https://example.com'));
        self::assertNull(Target::tryParse('product:1'), 'Extensions need their namespace.');

        $this->expectExceptionMessage('"page" is not a target.');
        Target::parse('page');
    }

    protected function extensions(): Extensions
    {
        return FixtureSite::extensions('base', 'site');
    }

    protected function config(): array
    {
        return [
            'app' => ['name' => 'Testsite', 'url' => 'https://example.org'],
            'theme' => 'acme/site',
            'languages' => ['de' => ['title' => 'Deutsch'], 'en' => ['title' => 'English'], 'fr' => ['title' => 'Français', 'fallback' => false]],
        ];
    }

    /**
     * @param array<string, string> $titles by language
     * @param list<array<mixed>>    $blocks
     */
    private function createPage(?string $parentId, array $titles, array $blocks = []): string
    {
        $translations = array_map(static fn(string $title): array => ['title' => $title, 'content' => ['fields' => ['heading' => $title], 'areas' => ['main' => $blocks]]], $titles);

        return $this->runner()->run(new CreatePage(self::LAYOUT, $translations, $parentId))->id;
    }

    private function publish(string ...$ids): void
    {
        $this->runner()->run(new PublishPages(array_values($ids)));
    }

    private function request(string $path, string $method = 'GET'): ResponseInterface
    {
        return $this->get(PageHandler::class)->handle(new ServerRequest($method, 'https://example.org' . $path));
    }

    private function html(string $path): string
    {
        $response = $this->request($path);
        self::assertSame(200, $response->getStatusCode(), $path);

        return (string) $response->getBody();
    }

    private function assertRedirect(string $location, string $path, int $status = 301): void
    {
        $response = $this->request($path);

        self::assertSame($status, $response->getStatusCode(), $path);
        self::assertSame($location, $response->getHeaderLine('Location'), $path);
    }

    private function assertStatus(int $status, string $path): void
    {
        try {
            $this->request($path);
            self::fail(\sprintf('%s was answered.', $path));
        } catch (HttpException $error) {
            self::assertSame($status, $error->status, $path);
        }
    }
}
