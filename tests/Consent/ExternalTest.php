<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Consent;

use Frootbox\Core\Config\Environment;
use Frootbox\Core\Consent\ConsentMarkup;
use Frootbox\Core\Consent\Embeds;
use Frootbox\Core\Consent\ExternalService;
use Frootbox\Core\Consent\ExternalServices;
use Frootbox\Core\Extension\ExtensionException;
use Frootbox\Core\Extension\Extensions;
use Frootbox\Core\Extension\Registry;
use Frootbox\Core\Page\CreatePage;
use Frootbox\Core\Page\PageHandler;
use Frootbox\Core\Page\PublishPages;
use Frootbox\Core\Tests\Support\FixtureSite;
use Frootbox\Core\Tests\Support\NotesTestCase;
use Frootbox\Core\View\ViewExtension;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Content of other providers loads only after consent (decision 0224).
 */
#[CoversClass(Embeds::class)]
#[CoversClass(ConsentMarkup::class)]
#[CoversClass(ExternalService::class)]
#[CoversClass(ExternalServices::class)]
#[CoversClass(ViewExtension::class)]
final class ExternalTest extends NotesTestCase
{
    public function testTheContentWaitsBehindAPlaceholder(): void
    {
        $html = $this->page([
            ['block' => 'acme/external/Map', 'fields' => ['place' => 'Münster']],
            ['block' => 'acme/external/Privacy'],
        ]);

        self::assertStringContainsString('<div class="map"><fb-external class="external" service="acme/external/osm" style="--external-ratio: 4/3"><template><iframe src="https://www.openstreetmap.org/export/embed.html?bbox=7.6,51.9,7.7,52.0" title="Karte"></iframe></template>', $html);
        self::assertSame(1, substr_count($html, '<iframe'), 'Only inside the template, so nothing loads.');
        self::assertStringContainsString('<div class="external__notice" role="group" aria-label="Karte: Münster"><p>This content comes from OpenStreetMap. When it loads, your browser sends data to OpenStreetMap Foundation. <a href="https://osmfoundation.org/wiki/Privacy_Policy" rel="noopener noreferrer" target="_blank">Privacy policy</a></p>', $html);
        self::assertStringContainsString('<div class="external__actions" hidden><button type="button" class="external__button" data-external-load>Load content</button><label class="external__always"><input type="checkbox" data-external-always> Always load OpenStreetMap</label></div>', $html, 'The buttons need the script.');
        self::assertStringContainsString('<p class="external__link"><a href="https://www.openstreetmap.org/#map=15/51.96/7.63" rel="noopener noreferrer">Open it at OpenStreetMap</a></p>', $html, 'Without JavaScript, a link to the provider.');

        self::assertStringContainsString('<button type="button" class="privacy-settings__button" hidden>Privacy settings</button><dialog class="privacy-settings__dialog" aria-labelledby="privacy-settings-title">', $html);
        self::assertStringContainsString('<li data-consent-reach="content"><label><input type="checkbox" value="acme/external/osm"> OpenStreetMap <span class="privacy-settings__provider">(OpenStreetMap Foundation)</span></label>', $html);

        self::assertSame(1, substr_count($html, '<script type="module" src="/assets/frootbox/core/assets/external.js?v='), 'The script of the core, once; its CSS is in the bundle.');
    }

    public function testEmbedsVideosAndMapsOfKnownProviders(): void
    {
        $html = $this->page([['block' => 'acme/external/Embeds', 'fields' => ['video' => 'https://youtu.be/dQw4w9WgXcQ?t=1m5s']]]);

        self::assertStringContainsString('<div class="video"><fb-external class="external" service="youtube" style="--external-ratio: 16 / 9"><template><iframe src="https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?start=65" title="Video: Rösten" loading="lazy" allow="autoplay; fullscreen; picture-in-picture; encrypted-media" allowfullscreen referrerpolicy="strict-origin-when-cross-origin"></iframe></template>', $html);
        self::assertStringContainsString('<a href="https://www.youtube.com/watch?v=dQw4w9WgXcQ&amp;t=65s" rel="noopener noreferrer">Open it at YouTube</a>', $html);
        self::assertStringContainsString('<div class="osm"><fb-external class="external" service="openstreetmap" style="--external-ratio: 4 / 3"><template><iframe src="https://www.openstreetmap.org/export/embed.html?bbox=', $html);
        self::assertStringContainsString('&amp;layer=mapnik&amp;marker=51.9607%2C7.6261" title="Karte: Münster" loading="lazy"', $html);
        self::assertStringContainsString('<div class="google"><fb-external class="external" service="google-maps" style="--external-ratio: 4 / 3"><template><iframe src="https://maps.google.com/maps?q=Prinzipalmarkt%201%2C%20M%C3%BCnster&amp;z=16&amp;output=embed" title="Map"', $html);
        self::assertStringContainsString('<div class="none"></div>', $html, 'OpenStreetMap needs coordinates.');
    }

    public function testKnowsTheAddressesOfVideos(): void
    {
        $embed = static fn(string $url): ?string => Embeds::video($url)?->src;

        self::assertSame('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', $embed('https://www.youtube.com/watch?v=dQw4w9WgXcQ&list=x'));
        self::assertSame('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', $embed('https://m.youtube.com/shorts/dQw4w9WgXcQ'));
        self::assertSame('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?start=90', $embed('https://www.youtube.com/embed/dQw4w9WgXcQ?start=90'));
        self::assertSame('https://player.vimeo.com/video/76979871?dnt=1', $embed('https://vimeo.com/76979871'));
        self::assertSame('https://player.vimeo.com/video/76979871?dnt=1&h=abc123def0', $embed('https://vimeo.com/76979871/abc123def0'));
        self::assertSame('https://player.vimeo.com/video/76979871?dnt=1', $embed('https://player.vimeo.com/video/76979871'));
        self::assertNull($embed('https://www.youtube.com/watch?v=short'));
        self::assertNull($embed('https://example.org/watch?v=dQw4w9WgXcQ'));
        self::assertNull($embed('javascript:alert(1)'));
        self::assertNull($embed(''));
    }

    public function testKnowsWhatAMapNeeds(): void
    {
        self::assertNull(Embeds::map(Embeds::OPENSTREETMAP, null, null, 'Münster'));
        self::assertNull(Embeds::map(Embeds::OPENSTREETMAP, 91.0, 7.0));
        self::assertNull(Embeds::map(Embeds::GOOGLE_MAPS, null, null));
        self::assertNull(Embeds::map('other', 51.0, 7.0));
        self::assertSame('https://www.openstreetmap.org/?mlat=51.5&mlon=7#map=19/51.5/7', Embeds::map(Embeds::OPENSTREETMAP, 51.5, 7.0, zoom: 30)?->link);
        self::assertSame('https://www.google.com/maps/search/?api=1&query=51.5%2C7', Embeds::map(Embeds::GOOGLE_MAPS, 51.5, 7.0)?->link);
    }

    public function testANameBelongsToOneExtension(): void
    {
        $registry = new Registry();
        $registry->add(ExternalService::class, new ExternalService('acme/video/youtube', 'YouTube', 'Google Ireland Limited', 'https://policies.google.com/privacy'));
        $registry->add(ExternalService::class, new ExternalService('acme/video/youtube', 'YouTube', 'Google', 'https://example.org'));

        $this->expectException(ExtensionException::class);
        new ExternalServices($registry)->all();
    }

    protected function environment(): Environment
    {
        return Environment::Production;
    }

    protected function extensions(): Extensions
    {
        return FixtureSite::extensions('base', 'site', 'external');
    }

    protected function config(): array
    {
        return ['app' => ['url' => 'https://example.org'], 'theme' => 'acme/site', 'languages' => ['de' => ['title' => 'Deutsch']]];
    }

    /**
     * @param list<array<string, mixed>> $blocks
     */
    private function page(array $blocks): string
    {
        $id = $this->runner()->run(new CreatePage('acme/base/Page', ['de' => ['title' => 'Start', 'content' => ['fields' => ['heading' => 'Start'], 'areas' => ['main' => $blocks]]]]))->id;
        $this->runner()->run(new PublishPages([$id]));

        return (string) $this->get(PageHandler::class)->handle(new ServerRequest('GET', 'https://example.org/'))->getBody();
    }
}
