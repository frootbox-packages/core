<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Component;

use Frootbox\Core\Action\Context;
use Frootbox\Core\Action\CurrentContext;
use Frootbox\Core\Action\DefinitionException;
use Frootbox\Core\Application;
use Frootbox\Core\Auth\AccessTokens;
use Frootbox\Core\Auth\TokenType;
use Frootbox\Core\Component\BlockGroup;
use Frootbox\Core\Component\BlockGroups;
use Frootbox\Core\Component\BlockSummary;
use Frootbox\Core\Component\Components;
use Frootbox\Core\Component\LayoutSummary;
use Frootbox\Core\Component\ListBlockGroups;
use Frootbox\Core\Component\ListBlocks;
use Frootbox\Core\Component\PreviewHandler;
use Frootbox\Core\Component\Previews;
use Frootbox\Core\Component\VariantSummary;
use Frootbox\Core\Extension\ExtensionPackage;
use Frootbox\Core\Extension\Extensions;
use Frootbox\Core\Extension\Registry;
use Frootbox\Core\Http\MiddlewareDispatcher;
use Frootbox\Core\Http\NotFoundHandler;
use Frootbox\Core\Tests\Support\FixtureSite;
use Frootbox\Core\Tests\Support\NotesTestCase;
use Frootbox\Core\Tests\Support\TemporaryProject;
use Frootbox\Core\User\CreateUser;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Http\Message\ResponseInterface;

#[CoversClass(Previews::class)]
#[CoversClass(PreviewHandler::class)]
#[CoversClass(BlockGroup::class)]
#[CoversClass(BlockGroups::class)]
#[CoversClass(ListBlockGroups::class)]
#[CoversClass(ListBlocks::class)]
#[CoversClass(BlockSummary::class)]
#[CoversClass(LayoutSummary::class)]
#[CoversClass(VariantSummary::class)]
final class PreviewsTest extends NotesTestCase
{
    private const string SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 320 200" id="preview"><rect width="320" height="200" style="fill:var(--color-bg,#fff)"/></svg>';

    private TemporaryProject $packages;

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->packages->remove();
    }

    public function testFindsPreviewsInTheBlockFolderAndInOverrides(): void
    {
        $hero = $this->get(Components::class)->block('acme/kit/Hero');
        self::assertNotNull($hero);

        self::assertSame('header', $hero->group);
        self::assertSame(['Stage', 'Intro'], $hero->keywords);
        self::assertSame([
            'Default' => $this->packages->root . '/kit/blocks/Hero/preview.svg',
            'Split' => $this->packages->root . '/look/overrides/acme/kit/blocks/Hero/preview.Split.webp',
        ], $hero->previews, 'SVG wins over WebP in one folder, a theme replaces a variant in any format.');
    }

    public function testListsGroupsKeywordsAndPreviewAddresses(): void
    {
        $blocks = $this->runner()->ask(new ListBlocks());
        $hero = array_values(array_filter($blocks, static fn(BlockSummary $block): bool => 'acme/kit/Hero' === $block->name))[0];
        $plain = array_values(array_filter($blocks, static fn(BlockSummary $block): bool => 'acme/kit/Plain' === $block->name))[0];

        self::assertSame('header', $hero->group);
        self::assertSame(['Stage', 'Intro'], $hero->keywords);
        self::assertMatchesRegularExpression('#^/previews/acme/kit/blocks/Hero/preview\.svg\?v=\d+$#', (string) $hero->preview);
        self::assertSame(['Default', 'Split'], array_map(static fn(VariantSummary $variant): string => $variant->name, $hero->variants));
        self::assertStringStartsWith('/previews/acme/kit/blocks/Hero/preview.Split.webp?v=', (string) $hero->variants[1]->preview);
        self::assertNull($plain->group);
        self::assertNull($plain->preview);
        self::assertNull($plain->variants[0]->preview);

        $groups = $this->runner()->ask(new ListBlockGroups());
        self::assertSame(array_keys(BlockGroups::CORE), array_map(static fn(BlockGroup $group): string => $group->name, $groups));
    }

    public function testDeliversPreviewsOnlyToThoseWhoMayArrangeBlocks(): void
    {
        $hero = array_values(array_filter($this->runner()->ask(new ListBlocks()), static fn(BlockSummary $block): bool => 'acme/kit/Hero' === $block->name))[0];
        $url = (string) $hero->preview;
        self::assertStringStartsWith('/previews/acme/kit/blocks/Hero/preview.svg', $url);

        self::assertSame(404, $this->deliver($url)->getStatusCode(), 'Guests get nothing: a preview may show a design that is not public yet.');

        $token = $this->token();
        $response = $this->deliver($url, $token);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('image/svg+xml', $response->getHeaderLine('Content-Type'));
        self::assertSame('private, max-age=31536000, immutable', $response->getHeaderLine('Cache-Control'));
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertStringStartsWith("default-src 'none'", $response->getHeaderLine('Content-Security-Policy'));
        self::assertSame(self::SVG, (string) $response->getBody());

        self::assertSame('private, no-cache', $this->deliver('/previews/acme/kit/blocks/Hero/preview.svg', $token)->getHeaderLine('Cache-Control'), 'Without the current version it is checked each time.');

        $split = $this->deliver('/previews/acme/kit/blocks/Hero/preview.Split.webp', $token);
        self::assertSame(200, $split->getStatusCode());
        self::assertSame('image/webp', $split->getHeaderLine('Content-Type'));

        foreach ([
            '/previews/acme/kit/blocks/Hero/preview.webp',        // replaced by the SVG of the same folder
            '/previews/acme/kit/blocks/Hero/preview.Split.svg',   // replaced by the theme
            '/previews/acme/kit/blocks/Hero/views/Default.twig',
            '/previews/acme/kit/blocks/Plain/preview.svg',
            '/previews/acme/kit/layouts/Hero/preview.svg',
            '/previews/acme/kit/blocks/Gone/preview.svg',
        ] as $path) {
            self::assertSame(404, $this->deliver($path, $token)->getStatusCode(), $path);
        }
    }

    public function testExtensionsAddGroupsUnderTheirPackage(): void
    {
        $registry = new Registry();
        $registry->forPackage('acme/shop', static function () use ($registry): void {
            $registry->blockGroup('acme/shop/products', 'Products');
        });

        $groups = new BlockGroups($registry->blockGroups());
        self::assertTrue($groups->has('acme/shop/products'));
        self::assertTrue($groups->has('teaser'));
        self::assertFalse($groups->has('acme/shop/other'));
        self::assertSame('acme/shop/products', $groups->all()[\count(BlockGroups::CORE)]->name);

        foreach (['teaser' => 'acme/shop', 'acme/other/products' => 'acme/shop', 'acme/shop/Products' => 'acme/shop', 'acme/shop/products' => 'acme/shop', 'header' => null] as $name => $package) {
            try {
                null === $package
                    ? $registry->blockGroup($name, 'Mine')
                    : $registry->forPackage($package, static fn() => $registry->blockGroup($name, 'Mine'));
                self::fail($name . ' should be refused.');
            } catch (DefinitionException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    protected function extensions(): Extensions
    {
        $this->packages = TemporaryProject::create();
        $kit = $this->packages->root . '/kit';
        $look = $this->packages->root . '/look';

        self::write([
            $kit . '/frootbox.yaml' => 'title: Kit',
            $kit . '/blocks/Hero/block.yaml' => "title: Hero\ngroup: header\nkeywords: [Stage, Intro]\n",
            $kit . '/blocks/Hero/views/Default.twig' => '<section>Hero</section>',
            $kit . '/blocks/Hero/views/Split.twig' => "{# frootbox\ntitle: Split\n#}<section>Split</section>",
            $kit . '/blocks/Hero/preview.svg' => self::SVG,
            $kit . '/blocks/Hero/preview.webp' => 'RIFF',
            $kit . '/blocks/Hero/preview.Split.svg' => self::SVG,
            $kit . '/blocks/Hero/preview.txt' => 'not a preview',
            $kit . '/blocks/Plain/views/Default.twig' => "{# frootbox\ntitle: Plain\n#}<p>Plain</p>",
            $look . '/frootbox.yaml' => "title: Look\ntheme:\n  parent: acme/base\n",
            $look . '/overrides/acme/kit/blocks/Hero/preview.Split.webp' => 'RIFF',
        ]);

        return new Extensions([
            ...FixtureSite::extensions('base')->packages,
            ExtensionPackage::fromManifest('acme/kit', $kit),
            ExtensionPackage::fromManifest('acme/look', $look),
        ]);
    }

    protected function config(): array
    {
        return ['theme' => 'acme/look'];
    }

    /**
     * @param array<string, string> $files
     */
    private static function write(array $files): void
    {
        foreach ($files as $file => $content) {
            if (!is_dir(\dirname($file))) {
                mkdir(\dirname($file), 0o777, true);
            }

            file_put_contents($file, $content);
        }
    }

    private function token(): string
    {
        $user = $this->runner()->run(new CreateUser('previews@example.com', 'Editor', roles: ['editor']), Context::system())->user;

        return $this->get(AccessTokens::class)->issue($user->id, TokenType::Api)->token;
    }

    private function deliver(string $path, ?string $token = null): ResponseInterface
    {
        $request = new Psr17Factory()->createServerRequest('GET', 'https://example.org' . $path);
        parse_str((string) parse_url($path, \PHP_URL_QUERY), $query);
        $request = $request->withQueryParams($query);

        // Each request starts as a guest, like in production.
        $this->get(CurrentContext::class)->set(Context::guest());
        $middleware = array_map($this->get(...), Application::MIDDLEWARE);

        try {
            return new MiddlewareDispatcher($middleware, $this->get(NotFoundHandler::class))->handle(null === $token ? $request : $request->withHeader('Authorization', 'Bearer ' . $token));
        } finally {
            $this->get(CurrentContext::class)->set(Context::system());
        }
    }
}
