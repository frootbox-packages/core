<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Url;

use Frootbox\Core\Action\InvalidInputException;
use Frootbox\Core\Extension\Extensions;
use Frootbox\Core\Field\Types\LinkType;
use Frootbox\Core\Language\Languages;
use Frootbox\Core\Page\CreatePage;
use Frootbox\Core\Page\PageHandler;
use Frootbox\Core\Page\PublishPages;
use Frootbox\Core\Tests\Support\FixtureSite;
use Frootbox\Core\Tests\Support\NotesTestCase;
use Frootbox\Core\Url\Target;
use Frootbox\Core\Url\TargetLink;
use Frootbox\Core\Url\Urls;
use Frootbox\Core\View\ComponentRenderer;
use Frootbox\Core\View\Delivery;
use Frootbox\Core\View\ViewExtension;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Http\Message\ResponseInterface;

#[CoversClass(LinkType::class)]
#[CoversClass(TargetLink::class)]
#[CoversClass(ViewExtension::class)]
#[CoversClass(Urls::class)]
#[CoversClass(ComponentRenderer::class)]
final class EntryAndLinksTest extends NotesTestCase
{
    private string $start;

    private string $about;

    protected function setUp(): void
    {
        parent::setUp();

        $this->start = $this->createPage(null, ['de' => 'Start', 'en' => 'Home', 'fr' => 'Accueil']);
        $this->about = $this->createPage($this->start, ['de' => 'Über uns', 'en' => 'About us']);
        $this->runner()->run(new PublishPages([$this->start, $this->about]));
    }

    public function testEntersByLanguageAndDomain(): void
    {
        $entry = $this->request('https://example.org/', ['Accept-Language' => 'fr-FR,fr;q=0.9,de;q=0.5']);
        self::assertSame(302, $entry->getStatusCode());
        self::assertSame('/fr', $entry->getHeaderLine('Location'));
        self::assertSame('Accept-Language', $entry->getHeaderLine('Vary'));
        self::assertSame('/de', $this->request('https://example.org/')->getHeaderLine('Location'), 'Without a wish the first language.');
        self::assertSame('/de', $this->request('https://example.org/', ['Accept-Language' => 'en'])->getHeaderLine('Location'), 'English lives on its own domain.');

        $english = (string) $this->request('https://example.com/about-us')->getBody();
        self::assertStringContainsString('<html lang="en">', $english);
        self::assertStringContainsString('<link rel="canonical" href="https://example.com/about-us">', $english);
        self::assertStringContainsString('<link rel="alternate" hreflang="de" href="https://example.org/de/ueber-uns">', $english);
        self::assertStringContainsString('<link rel="alternate" hreflang="x-default" href="https://example.org/de/ueber-uns">', $english);
        self::assertSame(200, $this->request('https://example.com/')->getStatusCode());
        self::assertSame(200, $this->request('https://example.org/de')->getStatusCode());
    }

    public function testLinksFollowTheirTargets(): void
    {
        $content = [
            'fields' => ['heading' => 'Links'],
            'areas' => ['main' => [[
                'block' => 'acme/base/Teaser',
                'fields' => [
                    'title' => 'Hallo',
                    'text' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [
                        ['type' => 'text', 'text' => 'Wir', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'page:' . $this->about]]]],
                        ['type' => 'text', 'text' => ' und ', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'page:01ARZ3NDEKTSV4RRFFQ69G5FAV']]]],
                        ['type' => 'text', 'text' => 'mehr', 'marks' => [['type' => 'link', 'attrs' => ['href' => '/mehr']]]],
                    ]]]],
                    'button' => ['target' => 'page:' . $this->about, 'text' => 'Mehr über uns'],
                ],
            ]]],
        ];
        $links = $this->createPage($this->start, ['de' => 'Links', 'en' => 'Links'], $content);
        $this->runner()->run(new PublishPages([$links]));

        $german = (string) $this->request('https://example.org/de/links')->getBody();
        self::assertStringContainsString('<a class="btn" href="/de/ueber-uns">Mehr über uns</a>', $german);
        self::assertStringContainsString('<p><a href="/de/ueber-uns">Wir</a> und <a href="/mehr">mehr</a></p>', $german, 'A target without URL keeps only the text.');

        $english = (string) $this->request('https://example.com/links')->getBody();
        self::assertStringContainsString('<a class="btn" href="/about-us">Mehr über uns</a>', $english);

        // Links into another domain are full URLs.
        $this->get(Delivery::class)->set($this->get(Languages::class)->get('de'));
        self::assertSame('https://example.com/about-us', $this->get(Urls::class)->href(Target::page($this->about), 'en'));
        self::assertSame('/de/ueber-uns', $this->get(ViewExtension::class)->url(['target' => 'page:' . $this->about]));
        self::assertSame('https://example.com', $this->get(ViewExtension::class)->url(['url' => 'https://example.com']));
        self::assertNull($this->get(ViewExtension::class)->url(null));

        $this->get(Delivery::class)->set($this->get(Languages::class)->get('de'), target: Target::page($this->about));
        self::assertSame([
            ['code' => 'de', 'title' => 'Deutsch', 'url' => '/de/ueber-uns', 'current' => true, 'fallback' => false],
            ['code' => 'en', 'title' => 'English', 'url' => 'https://example.com/about-us', 'current' => false, 'fallback' => false],
            ['code' => 'fr', 'title' => 'Français', 'url' => '/fr/ueber-uns', 'current' => false, 'fallback' => true],
        ], $this->get(ViewExtension::class)->alternates());

        $html = $this->get(ComponentRenderer::class)->block(['block' => 'acme/base/Teaser', 'fields' => ['title' => 'x', 'button' => ['target' => 'page:01ARZ3NDEKTSV4RRFFQ69G5FAV', 'text' => 'Weg']]]);
        self::assertStringNotContainsString('class="btn"', $html, 'The theme decides; url() is null for a target without URL.');
    }

    public function testLinksNeedATargetOrAUrl(): void
    {
        $teaser = static fn(array $button): array => ['fields' => ['heading' => 'x'], 'areas' => ['main' => [['block' => 'acme/base/Teaser', 'fields' => ['title' => 'x', 'button' => $button]]]]];

        foreach ([[[], '/'], [['url' => '/x', 'target' => 'page:' . $this->about], '/'], [['target' => 'irgendwas'], '/target']] as [$button, $pointer]) {
            try {
                $this->createPage($this->start, ['de' => 'Falsch'], $teaser($button)['areas']['main']);
                self::fail('The link was accepted: ' . json_encode($button));
            } catch (InvalidInputException $error) {
                self::assertArrayHasKey('/translations/de/content/areas/main/0/fields/button' . ('/' === $pointer ? '' : $pointer), $error->errors, (string) json_encode($error->errors));
            }
        }
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
            'languages' => [
                'de' => ['title' => 'Deutsch', 'prefix' => 'de'],
                'en' => ['title' => 'English', 'domain' => 'example.com'],
                'fr' => ['title' => 'Français'],
            ],
        ];
    }

    /**
     * @param array<string, string>    $titles by language
     * @param array<string, mixed>|list<array<mixed>> $content
     */
    private function createPage(?string $parentId, array $titles, array $content = []): string
    {
        $translations = array_map(static fn(string $title): array => ['title' => $title, 'content' => isset($content['fields']) ? $content : ['fields' => ['heading' => $title], 'areas' => ['main' => $content]]], $titles);

        return $this->runner()->run(new CreatePage('acme/base/Page', $translations, $parentId))->id;
    }

    /**
     * @param array<string, string> $headers
     */
    private function request(string $url, array $headers = []): ResponseInterface
    {
        return $this->get(PageHandler::class)->handle(new ServerRequest('GET', $url, $headers));
    }
}
