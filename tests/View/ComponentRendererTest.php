<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\View;

use Frootbox\Core\Component\ComponentKind;
use Frootbox\Core\Component\Instance;
use Frootbox\Core\Config\Environment;
use Frootbox\Core\Extension\ExtensionPackage;
use Frootbox\Core\Extension\Extensions;
use Frootbox\Core\Field\FieldTypes;
use Frootbox\Core\Field\Types\HeadlineType;
use Frootbox\Core\Field\Types\LinkType;
use Frootbox\Core\Field\Types\ListType;
use Frootbox\Core\Field\Types\RichTextType;
use Frootbox\Core\Field\Types\TextType;
use Frootbox\Core\Tests\Support\FixtureSite;
use Frootbox\Core\Tests\Support\TemporaryProject;
use Frootbox\Core\View\ComponentRenderer;
use Frootbox\Core\View\EmptyElements;
use Frootbox\Core\View\FileLoader;
use Frootbox\Core\View\Frame;
use Frootbox\Core\View\ListValue;
use Frootbox\Core\View\Scope;
use Frootbox\Core\View\TwigFactory;
use Frootbox\Core\View\ViewException;
use Frootbox\Core\View\ViewExtension;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Twig\Environment as Twig;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;

#[CoversClass(ComponentRenderer::class)]
#[CoversClass(EmptyElements::class)]
#[CoversClass(ViewExtension::class)]
#[CoversClass(TwigFactory::class)]
#[CoversClass(FileLoader::class)]
#[CoversClass(Frame::class)]
#[CoversClass(Scope::class)]
#[CoversClass(ListValue::class)]
#[CoversClass(Instance::class)]
#[CoversClass(HeadlineType::class)]
#[CoversClass(TextType::class)]
#[CoversClass(RichTextType::class)]
#[CoversClass(LinkType::class)]
#[CoversClass(ListType::class)]
final class ComponentRendererTest extends TestCase
{
    private TemporaryProject $project;

    protected function setUp(): void
    {
        $this->project = TemporaryProject::create();
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testRendersBlocksWithFields(): void
    {
        $html = $this->renderer('acme/base')->block([
            'block' => 'acme/base/Teaser',
            'options' => ['imageHeight' => 550],
            'fields' => [
                'title' => 'Fish & <Chips>',
                'text' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Fresh', 'marks' => [['type' => 'bold']]]]]]],
                'button' => ['url' => 'https://example.com', 'text' => 'Order', 'newWindow' => true],
            ],
        ]);

        self::assertSame(
            '<section class="teaser" style="--h: 550px"><h2>Fish &amp; &lt;Chips&gt;</h2><p><strong>Fresh</strong></p>'
            . '<a href="https://example.com" target="_blank" rel="noopener noreferrer">Order</a></section>' . "\n",
            $html,
        );
    }

    public function testUsesOverridesAndVariants(): void
    {
        $renderer = $this->renderer();
        $teaser = ['block' => 'acme/base/Teaser', 'fields' => ['title' => 'Hi', 'button' => ['url' => '/contact', 'text' => 'Contact']]];

        self::assertSame('<article class="site-teaser"><h3>Hi</h3><a class="btn" href="/contact">Contact</a></article>' . "\n", $renderer->block($teaser));
        self::assertSame('<article class="left rounded">Hi</article>' . "\n", $renderer->block([...$teaser, 'variant' => 'ImageLeft']));
        self::assertSame('<article class="left">Hi</article>' . "\n", $renderer->block([...$teaser, 'variant' => 'ImageLeft', 'options' => ['rounded' => false]]));
    }

    public function testRendersListsAndAreas(): void
    {
        $html = $this->renderer()->layout([
            'layout' => 'acme/base/Page',
            'fields' => ['heading' => 'Home'],
            'areas' => ['main' => [
                ['block' => 'acme/base/Columns', 'variant' => 'Wide', 'areas' => [
                    'left' => [['block' => 'acme/news/List']],
                    'right' => [
                        ['block' => 'acme/site/Hero', 'fields' => [
                            'intro' => "Line one\nLine <two>",
                            'slides' => [
                                ['title' => 'First', 'link' => ['url' => '/first']],
                                ['title' => 'Second'],
                            ],
                        ]],
                        ['block' => 'acme/other/Promo'],
                    ],
                ]],
            ]],
        ]);

        self::assertSame(
            '<main><h1>Home</h1><div class="columns wide"><ul class="news"></ul>' . "\n"
            . '|<div class="hero">Line one<br>' . "\n" . 'Line &lt;two&gt;<ul><li>First<a href="/first" dir="ltr">/first</a></li><li>Second</li></ul>2</div>' . "\n"
            . '<!-- Frootbox: unknown block acme/other/Promo --></div>' . "\n"
            . '</main>' . "\n",
            $html,
        );
    }

    public function testLeavesOutEmptyFieldsAndBlankEntries(): void
    {
        $html = $this->renderer()->block(['block' => 'acme/site/Hero', 'fields' => [
            'slides' => [['title' => 'First'], [], ['title' => ' ']],
        ]]);

        self::assertSame('<div class="hero"><ul><li>First</li></ul>1</div>' . "\n", $html, 'Visitors get neither marks nor blank entries (decision 0144).');

        self::assertSame(
            '<section><ul><li><h3>Title</h3></li></ul></section>',
            EmptyElements::remove('<section><header class="head"><h2>' . EmptyElements::MARK . '</h2> <p>' . EmptyElements::MARK . "</p>\n</header><ul><li><h3>Title</h3><p>" . EmptyElements::MARK . '</p></li></ul></section>'),
            'An element goes with its empty field, and so does one that only held such elements.',
        );
        self::assertSame('<img alt="">x', EmptyElements::remove('<img alt="' . EmptyElements::MARK . '">x&lt;!--fb:empty--&gt;'), 'Marks elsewhere are dropped.');
        self::assertSame('<p>Text</p>', EmptyElements::remove('<p>Text</p>'));
    }

    public function testLeavesOutUnknownBlocksInProduction(): void
    {
        $container = FixtureSite::container($this->project, 'acme/site', Environment::Production);

        self::assertSame('', $this->get($container, ComponentRenderer::class)->block(['block' => 'acme/other/Promo']));
        self::assertStringStartsWith('<article', $this->get($container, ComponentRenderer::class)->block(['block' => 'acme/base/Teaser', 'fields' => ['title' => 'x']]));
        // Twig writes no file for a template another test has loaded already, so ask it where it compiles to.
        self::assertSame(realpath($this->project->root) . '/var/cache/twig', $this->get($container, Twig::class)->getCache(), 'Templates are compiled in production.');
    }

    public function testLeavesOutUnsafeLinks(): void
    {
        $html = $this->renderer('acme/base')->block(['block' => 'acme/base/Teaser', 'fields' => ['title' => 'x', 'button' => ['url' => 'javascript:alert(1)']]]);

        self::assertStringNotContainsString('<a', $html);
    }

    public function testExplainsMistakesInTemplates(): void
    {
        $root = $this->project->root . '/mistakes';
        mkdir($root . '/blocks/Field/views', 0o777, true);
        mkdir($root . '/blocks/Area/views', 0o777, true);
        file_put_contents($root . '/frootbox.yaml', 'title: Mistakes');
        file_put_contents($root . '/blocks/Field/views/Default.twig', "{# frootbox\ntitle: F\nfields:\n  title: { type: headline }\n#}{{ field('titel') }}");
        file_put_contents($root . '/blocks/Area/views/Default.twig', "{# frootbox\ntitle: A\n#}{{ area('main') }}");

        $extensions = FixtureSite::extensions('news');
        $extensions = new Extensions([...$extensions->packages, ExtensionPackage::fromManifest('acme/mistakes', $root)]);
        $renderer = $this->get(FixtureSite::container($this->project, null, extensions: $extensions), ComponentRenderer::class);

        foreach (['Field' => 'There is no field "titel". Fields: title.', 'Area' => 'acme/mistakes/Area has no area "main". Areas: none.'] as $block => $message) {
            try {
                $renderer->block(['block' => 'acme/mistakes/' . $block]);
                self::fail('No error for ' . $block);
            } catch (RuntimeError $error) {
                self::assertStringContainsString($message, $error->getMessage());
            }
        }
    }

    public function testFunctionsWorkOnlyInBlocks(): void
    {
        $twig = $this->get(FixtureSite::container($this->project), Twig::class);
        file_put_contents($this->project->root . '/plain.twig', "{{ field('title') }}");

        $this->expectException(RuntimeError::class);
        $this->expectExceptionMessage('field() works only in templates of blocks and layouts');

        $twig->render($this->project->root . '/plain.twig');
    }

    public function testLoadsOnlyAbsoluteTwigFiles(): void
    {
        $loader = new FileLoader();
        file_put_contents($this->project->root . '/a.twig', 'x');
        file_put_contents($this->project->root . '/a.php', 'x');

        self::assertTrue($loader->exists($this->project->root . '/a.twig'));
        self::assertFalse($loader->exists($this->project->root . '/a.php'));
        self::assertFalse($loader->exists('a.twig'));
        self::assertTrue($loader->isFresh($this->project->root . '/a.twig', time() + 10));
        self::assertSame($this->project->root . '/a.twig', $loader->getCacheKey($this->project->root . '/a.twig'));

        $this->expectException(LoaderError::class);
        $loader->getSourceContext('/etc/passwd');
    }

    public function testRejectsBrokenInstances(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The area "main" must be a list of blocks.');

        Instance::fromArray(['layout' => 'acme/base/Page', 'areas' => ['main' => ['block' => 'x']]], ComponentKind::Layout);
    }

    public function testInstancesNeedAName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A block needs its name under "block".');

        Instance::fromArray(['layout' => 'acme/base/Page']);
    }

    public function testScopesExplainUnknownFields(): void
    {
        $this->expectException(ViewException::class);
        $this->expectExceptionMessage('There is no field "x". Fields: none.');

        new Scope([], [], $this->get(FixtureSite::container($this->project), FieldTypes::class))->value('x');
    }

    private function renderer(string $theme = 'acme/site', Environment $environment = Environment::Development): ComponentRenderer
    {
        return $this->get(FixtureSite::container($this->project, $theme, $environment), ComponentRenderer::class);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function get(ContainerInterface $container, string $class): object
    {
        $service = $container->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
    }
}
