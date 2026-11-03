<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Consent;

use Frootbox\Core\Config\Environment;
use Frootbox\Core\Consent\ExternalMarkup;
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
#[CoversClass(ExternalMarkup::class)]
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

        self::assertStringContainsString('<fb-privacy-settings class="privacy-settings"><button type="button" class="privacy-settings__button" hidden>Privacy settings</button><dialog class="privacy-settings__dialog" aria-labelledby="privacy-settings-title">', $html);
        self::assertStringContainsString('<li><label><input type="checkbox" value="acme/external/osm"> OpenStreetMap <span class="privacy-settings__provider">(OpenStreetMap Foundation)</span></label>', $html);

        self::assertSame(1, substr_count($html, '<script type="module" src="/assets/frootbox/core/assets/external.js?v='), 'The script of the core, once; its CSS is in the bundle.');
    }

    public function testWithoutServicesThereAreNoPrivacySettings(): void
    {
        self::assertSame('', new ExternalMarkup(static fn(string $text): string => $text)->settings([]));
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
