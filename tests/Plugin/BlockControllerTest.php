<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Plugin;

use Frootbox\Core\Cache\PageCache;
use Frootbox\Core\Component\BlockContext;
use Frootbox\Core\Component\ComponentKind;
use Frootbox\Core\Component\ComponentLoader;
use Frootbox\Core\Component\Components;
use Frootbox\Core\Config\Environment;
use Frootbox\Core\Extension\Extensions;
use Frootbox\Core\Page\CreatePage;
use Frootbox\Core\Page\PageHandler;
use Frootbox\Core\Page\PublishPages;
use Frootbox\Core\Tests\Support\FixtureSite;
use Frootbox\Core\Tests\Support\NotesTestCase;
use Frootbox\Core\View\ComponentRenderer;
use Frootbox\Core\View\Delivery;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Http\Message\ResponseInterface;

#[CoversClass(ComponentLoader::class)]
#[CoversClass(ComponentRenderer::class)]
#[CoversClass(BlockContext::class)]
#[CoversClass(Delivery::class)]
#[CoversClass(PageCache::class)]
final class BlockControllerTest extends NotesTestCase
{
    private string $start;

    protected function setUp(): void
    {
        parent::setUp();

        $this->start = $this->runner()->run(new CreatePage('acme/base/Page', ['de' => ['title' => 'Start', 'content' => [
            'fields' => ['heading' => 'Start'],
            'areas' => ['main' => [['block' => 'acme/plugin/Greeting', 'options' => ['loud' => true]]]],
        ]]]))->id;
        $this->runner()->run(new PublishPages([$this->start]));
    }

    public function testFindsTheControllerByTheNamespaceOfTheExtension(): void
    {
        $greeting = $this->get(Components::class)->get(ComponentKind::Block, 'acme/plugin/Greeting');

        self::assertSame('Frootbox\Core\Tests\Fixtures\Plugin\Blocks\Greeting', $greeting?->controller);
        self::assertSame(['name', 'page'], $greeting->query);
        self::assertNull($this->get(Components::class)->get(ComponentKind::Block, 'acme/base/Teaser')?->controller);
    }

    public function testTheControllerGivesTheViewItsData(): void
    {
        $html = (string) $this->request('/?name=Ada&page=2&utm_source=x')->getBody();

        self::assertStringContainsString('<p class="greeting" data-page="2" data-target="page:' . $this->start . '">HELLO ADA IN DE (', $html, 'Options, the declared query and the target.');
        self::assertStringNotContainsString('taken', $html, 'The id stays the one of the block.');
    }

    public function testThePageCacheTellsPagesApartByDeclaredParameters(): void
    {
        self::assertSame('miss', $this->request('/?name=Ada')->getHeaderLine(PageCache::HEADER));
        self::assertSame('hit', $this->request('/?name=Ada&utm_source=newsletter')->getHeaderLine(PageCache::HEADER));

        $bob = $this->request('/?name=Bob');
        self::assertSame('miss', $bob->getHeaderLine(PageCache::HEADER));
        self::assertStringContainsString('HELLO BOB', (string) $bob->getBody());
        self::assertSame('miss', $this->request('/?page=1&name=Bob')->getHeaderLine(PageCache::HEADER), 'page=1 is a parameter of its own.');
        self::assertSame('hit', $this->request('/?name=Bob&page=1')->getHeaderLine(PageCache::HEADER), 'In any order.');

        $plain = $this->request('/');
        self::assertSame('miss', $plain->getHeaderLine(PageCache::HEADER));
        self::assertStringContainsString('HELLO WORLD', (string) $plain->getBody());
        self::assertSame('hit', $this->request('/?name=Bob')->getHeaderLine(PageCache::HEADER));
    }

    protected function environment(): Environment
    {
        return Environment::Production;
    }

    protected function extensions(): Extensions
    {
        return FixtureSite::extensions('base', 'site', 'plugin');
    }

    protected function config(): array
    {
        return ['app' => ['name' => 'Testsite', 'url' => 'https://example.org'], 'theme' => 'acme/site', 'languages' => ['de' => ['title' => 'Deutsch']]];
    }

    private function request(string $path): ResponseInterface
    {
        $request = new ServerRequest('GET', 'https://example.org' . $path);
        parse_str($request->getUri()->getQuery(), $query);

        return $this->get(PageHandler::class)->handle($request->withQueryParams($query));
    }
}
