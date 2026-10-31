<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Page;

use Frootbox\Core\Action\InvalidInputException;
use Frootbox\Core\Event\ListenerProvider;
use Frootbox\Core\Extension\Extensions;
use Frootbox\Core\Http\Exception\HttpException;
use Frootbox\Core\Http\SiteUrl;
use Frootbox\Core\Page\CreatePage;
use Frootbox\Core\Page\PageDetails;
use Frootbox\Core\Page\PageHandler;
use Frootbox\Core\Page\PageResolver;
use Frootbox\Core\Page\PublishPages;
use Frootbox\Core\Page\ResolvedPage;
use Frootbox\Core\Tests\Support\FixtureSite;
use Frootbox\Core\Tests\Support\NotesTestCase;
use Frootbox\Core\View\BuildingHead;
use Frootbox\Core\View\Document;
use Frootbox\Core\View\Head;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Http\Message\ResponseInterface;

#[CoversClass(PageHandler::class)]
#[CoversClass(PageResolver::class)]
#[CoversClass(ResolvedPage::class)]
#[CoversClass(Document::class)]
#[CoversClass(Head::class)]
#[CoversClass(BuildingHead::class)]
#[CoversClass(SiteUrl::class)]
final class PageDeliveryTest extends NotesTestCase
{
    private const string LAYOUT = 'acme/base/Page';

    public function testDeliversPublishedPages(): void
    {
        $start = $this->createPage(null, 'Start', content: ['fields' => ['heading' => 'Willkommen'], 'areas' => ['main' => [['block' => 'acme/base/Teaser', 'fields' => ['title' => 'Frisch geröstet']]]]]);
        $about = $this->createPage($start->id, 'Über uns');
        $this->runner()->run(new PublishPages([$start->id, $about->id]));

        $response = $this->request('/');
        $html = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/html; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertStringStartsWith("<!doctype html>\n<html lang=\"de\">", $html);
        self::assertStringContainsString('<title>Testsite</title>', $html, 'The start page has the name of the site as title.');
        self::assertStringContainsString('<link rel="canonical" href="https://example.org/">', $html);
        self::assertStringContainsString('<style>@layer reset, tokens, base, layouts, blocks, theme, project;</style>', $html);
        self::assertStringContainsString('<style>@layer tokens {:root{--color-primary:#c00;', $html);
        self::assertStringContainsString('<main><h1>Willkommen</h1><article class="site-teaser"><h3>Frisch geröstet</h3>', $html);

        // Only what the page uses, each in its layer; theme files on every page.
        self::assertStringContainsString('/assets/acme/base/assets/reset.css?v=', $html);
        self::assertStringContainsString('/assets/acme/site/overrides/acme/base/blocks/Teaser/assets/teaser.css?v=', $html);
        self::assertStringContainsString('/assets/acme/site/assets/site.css?v=', $html);
        self::assertStringContainsString('<script type="module" src="/assets/acme/base/blocks/Teaser/assets/teaser.js?v=', $html);
        self::assertSame(2, preg_match_all('/layer\(blocks\)/', $html), 'The Teaser override brings teaser.css and extra.css.');

        $about = (string) $this->request('/ueber-uns')->getBody();
        self::assertStringContainsString('<title>Über uns – Testsite</title>', $about);
        self::assertStringContainsString('<link rel="canonical" href="https://example.org/ueber-uns">', $about);
        self::assertStringNotContainsString('teaser', $about, 'Pages without the Teaser do not load its files.');
    }

    public function testRedirectsTrailingSlashesAndHidesDrafts(): void
    {
        $start = $this->createPage(null, 'Start');
        $about = $this->createPage($start->id, 'Über uns');
        $this->runner()->run(new PublishPages([$start->id, $about->id]));
        $this->createPage($start->id, 'Entwurf');

        $redirect = $this->request('/ueber-uns/?a=1');
        self::assertSame(301, $redirect->getStatusCode());
        self::assertSame('/ueber-uns?a=1', $redirect->getHeaderLine('Location'));

        $this->assertStatus(404, '/entwurf');
        $this->assertStatus(404, '/ueber-uns/team');
        $this->assertStatus(405, '/', 'POST');
        self::assertSame(200, $this->request('/', 'HEAD')->getStatusCode());
    }

    public function testShowsTheWelcomePageWithoutStartPage(): void
    {
        $this->createPage(null, 'Start');

        self::assertStringContainsString('läuft', (string) $this->request('/')->getBody(), 'A draft start page is not delivered.');
        $this->assertStatus(404, '/irgendwas');
    }

    public function testListenersAddToTheHead(): void
    {
        $start = $this->createPage(null, 'Start');
        $this->runner()->run(new PublishPages([$start->id]));
        $this->get(ListenerProvider::class)->listen(BuildingHead::class, static function (BuildingHead $event): void {
            $event->head->title = 'Anders';
            $event->head->add('<meta name="robots" content="noindex">');
        });

        $html = (string) $this->request('/')->getBody();

        self::assertStringContainsString('<title>Anders</title>', $html);
        self::assertStringContainsString('<meta name="robots" content="noindex">', $html);
    }

    public function testReservesPathsBelowTheStartPage(): void
    {
        $start = $this->createPage(null, 'Start');

        try {
            $this->createPage($start->id, 'API', 'api');
            self::fail('The reserved slug was accepted.');
        } catch (InvalidInputException $error) {
            self::assertSame(['/translations/de/slug' => '"api" is reserved directly below the start page. Reserved: api, assets, admin, media, en.'], $error->errors);
        }

        $docs = $this->createPage($start->id, 'Doku');
        self::assertSame('api', $this->createPage($docs->id, 'API', 'api')->draft?->translations['de']->slug, 'Deeper down it is a normal slug.');
    }

    protected function extensions(): Extensions
    {
        return FixtureSite::extensions('base', 'site');
    }

    protected function config(): array
    {
        return [
            'app' => ['name' => 'Testsite', 'url' => 'https://example.org/'],
            'theme' => 'acme/site',
            'languages' => ['de' => ['title' => 'Deutsch'], 'en' => ['title' => 'English']],
        ];
    }

    /**
     * @param array<string, mixed> $content
     */
    private function createPage(?string $parentId, string $title, ?string $slug = null, ?array $content = null): PageDetails
    {
        $translation = ['title' => $title, 'content' => $content ?? ['fields' => ['heading' => $title]]];

        return $this->runner()->run(new CreatePage(self::LAYOUT, ['de' => null === $slug ? $translation : [...$translation, 'slug' => $slug]], $parentId));
    }

    private function request(string $path, string $method = 'GET'): ResponseInterface
    {
        return $this->get(PageHandler::class)->handle(new ServerRequest($method, 'https://example.org' . $path));
    }

    private function assertStatus(int $status, string $path, string $method = 'GET'): void
    {
        try {
            $this->request($path, $method);
            self::fail(\sprintf('%s %s was answered.', $method, $path));
        } catch (HttpException $error) {
            self::assertSame($status, $error->status, $path);
        }
    }
}
