<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Heading;

use Frootbox\Core\Asset\AssetList;
use Frootbox\Core\Config\Environment;
use Frootbox\Core\Field\Field;
use Frootbox\Core\Field\FieldTypes;
use Frootbox\Core\Field\Types\RichTextType;
use Frootbox\Core\Heading\Headings;
use Frootbox\Core\Heading\Outline;
use Frootbox\Core\Page\EditMode;
use Frootbox\Core\Tests\Support\FixtureSite;
use Frootbox\Core\Tests\Support\TemporaryProject;
use Frootbox\Core\View\ComponentRenderer;
use Frootbox\Core\View\Scope;
use Frootbox\Core\View\ViewException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Twig\Markup;

#[CoversClass(Outline::class)]
#[CoversClass(Headings::class)]
#[CoversClass(Scope::class)]
#[CoversClass(ComponentRenderer::class)]
#[CoversClass(RichTextType::class)]
final class HeadingsTest extends TestCase
{
    private TemporaryProject $project;

    private ContainerInterface $container;

    private int $blocks = 0;

    protected function setUp(): void
    {
        $this->project = TemporaryProject::create();
        $this->container = FixtureSite::container($this->project, 'acme/outline', Environment::Production, FixtureSite::extensions('outline'));
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testTheSameBlockGetsTheLevelOfItsPlace(): void
    {
        $html = $this->page('acme/outline/Page', ['title' => 'About us'], [
            $this->block('Hero', ['title' => 'Fresh every week']),
            $this->block('Section', ['title' => 'Our beans'], ['content' => [
                $this->block('Hero', ['title' => 'Kenya']),
                $this->block('Text', ['body' => self::richText([1, 'Taste'], [2, 'Notes'], [1, 'Roast'])]),
            ]]),
            $this->block('Section', [], ['content' => [$this->block('Hero', ['title' => 'In a section without heading'])]]),
        ]);

        self::assertSame([
            'h1 About us',
            'h2 Fresh every week',
            'h2 Our beans',
            'h3 Kenya',
            'h3 Taste',
            'h4 Notes',
            'h3 Roast',
            'h2 In a section without heading',
            'h2 Contact',
        ], self::outline($html));
        self::assertStringContainsString('<h1 class="page__title" data-x="a&quot;b">About us</h1>', $html);
        self::assertStringContainsString('<h3 data-rank="1">Taste</h3><h4 data-rank="2">Notes</h4>', $html, 'Rich text keeps its rank for the styles.');
    }

    public function testTheFirstHeadingOfAOnePagerIsTheH1(): void
    {
        $html = $this->page('acme/outline/OnePage', [], [
            $this->block('Hero', ['title' => 'Bohnenglück']),
            $this->block('Hero', ['title' => 'Second hero']),
            $this->block('Faq', ['title' => 'Questions', 'items' => [['question' => 'Fair trade?', 'answer' => self::richText([1, 'Dropped'])], ['question' => 'Decaf?']]]),
            $this->block('Faq', ['items' => [['question' => 'Without a title?']]]),
        ]);

        self::assertSame([
            'h1 Bohnenglück',
            'h2 Second hero',
            'h2 Questions',
            'h3 Fair trade?',
            'h3 Decaf?',
            'h2 Without a title?',
        ], self::outline($html));
        self::assertStringContainsString('<summary><h3 class="faq__question">Fair trade?</h3></summary>', $html);
        self::assertStringNotContainsString('Dropped', $html, 'The answer allows no headings.');
    }

    public function testNeverSkipsALevelOrRepeatsTheH1(): void
    {
        $nested = $this->block('Hero', ['title' => 'Deep down']);

        for ($depth = 0; $depth < 7; ++$depth) {
            $nested = $this->block('Section', ['title' => 'Level ' . (7 - $depth)], ['content' => [$nested]]);
        }

        $html = $this->page('acme/outline/OnePage', [], [
            $this->block('Text', ['body' => self::richText([3, 'Small first'], [1, 'Then big'])]),
            $this->block('Text', ['title' => 'Text', 'body' => self::richText([3, 'Jumps two'])]),
            $nested,
        ]);

        self::assertSame([
            'h1 Small first',
            'h2 Then big',
            'h2 Text',
            'h3 Jumps two',
            'h2 Level 1',
            'h3 Level 2',
            'h4 Level 3',
            'h5 Level 4',
            'h6 Level 5',
            'h6 Level 6',
            'h6 Level 7',
            'h6 Deep down',
        ], self::outline($html));
    }

    public function testAnAreaWithAFixedLevelNeverGetsTheH1(): void
    {
        $html = $this->page('acme/outline/Page', [], [$this->block('Image')], ['aside' => [$this->block('Hero', ['title' => 'Opening hours'])]]);

        self::assertSame(['h1 Our page', 'h2 Opening hours', 'h2 Contact'], self::outline($html));
    }

    public function testAPageWithoutHeadingGetsItsTitleForScreenReaders(): void
    {
        $assets = new AssetList();
        $html = $this->page('acme/outline/OnePage', [], [$this->block('Image'), $this->block('Hero', ['title' => ''])], assets: $assets);

        self::assertStringContainsString('<main id="content"><h1 class="fb-visually-hidden">Our page</h1><figure>', $html);
        self::assertStringContainsString('.fb-visually-hidden{position:absolute', implode('', $assets->inlineStyles()));
        self::assertStringNotContainsString('<h2', $html, 'An empty heading leaves nothing behind.');
        self::assertStringNotContainsString('class="hero"', $html, 'Like an empty field it takes its element along.');

        $assets = new AssetList();
        self::assertStringNotContainsString('fb-visually-hidden', $this->page('acme/outline/OnePage', [], [$this->block('Hero', ['title' => 'Hi'])], assets: $assets));
        self::assertSame([], $assets->inlineStyles());
    }

    public function testTheEditingModeShowsAnEmptyHeadingAtItsLevelWithoutCountingIt(): void
    {
        $editMode = $this->container->get(EditMode::class);
        self::assertInstanceOf(EditMode::class, $editMode);
        $editMode->set(true, true);

        $html = $this->page('acme/outline/OnePage', [], [
            $this->block('Hero', ['title' => '']),
            $this->block('Hero', ['title' => 'Counts']),
            $this->block('Text', ['body' => self::richText([1, 'Below'])]),
        ]);

        self::assertMatchesRegularExpression('~<h1 class="hero__title" data-fb-field="title" [^>]*data-fb-placeholder="Big words"[^>]*></h1>~', $html, 'The placeholder at the level it would get.');
        self::assertMatchesRegularExpression('~<h1 class="hero__title" data-fb-field="title" [^>]*>Counts</h1>~', $html, 'The empty one does not count.');
        self::assertStringContainsString('data-fb-headings="3" data-fb-heading-level="2"', $html, 'The editor shows rich text headings at their level.');
        self::assertStringContainsString('<h2 data-rank="1">Below</h2>', $html);
    }

    public function testHeadingChecksItsUse(): void
    {
        $types = $this->get(FieldTypes::class);
        $fields = ['title' => new Field('title', 'headline', 'Title'), 'body' => new Field('body', 'richtext', 'Body')];
        $cases = [
            'outside blocks and layouts' => static fn(): Markup => new Scope($fields, ['title' => 'x'], $types)->heading('title'),
            'rich text' => static fn(): Markup => new Scope($fields, [], $types, headings: new Headings())->heading('body'),
            'a bad attribute' => static fn(): Markup => new Scope($fields, ['title' => 'x'], $types, headings: new Headings())->heading('title', 0, ['on click' => 'x']),
            'an attribute that is no text' => static fn(): Markup => new Scope($fields, ['title' => 'x'], $types, headings: new Headings())->heading('title', 0, ['class' => ['a']]),
        ];

        foreach ($cases as $case => $call) {
            try {
                $call();
                self::fail('Accepted ' . $case);
            } catch (ViewException) {
            }
        }

        self::assertStringContainsString('<h1 class="text__title">Alone</h1>', $this->renderer()->block(['block' => 'acme/outline/Text', 'fields' => ['title' => 'Alone']]), 'A block rendered alone starts at h1.');
    }

    /**
     * @param array<string, mixed>       $fields
     * @param list<array<string, mixed>> $main
     * @param array<string, mixed>       $areas
     */
    private function page(string $layout, array $fields, array $main, array $areas = [], ?AssetList $assets = null): string
    {
        return $this->renderer()->layout(['layout' => $layout, 'fields' => $fields, 'areas' => ['main' => $main, ...$areas]], $assets, 'Our page');
    }

    /**
     * @param array<string, mixed> $fields
     * @param array<string, mixed> $areas
     *
     * @return array<string, mixed>
     */
    private function block(string $name, array $fields = [], array $areas = []): array
    {
        return ['block' => 'acme/outline/' . $name, 'id' => 'b' . ++$this->blocks, 'fields' => $fields, 'areas' => $areas];
    }

    /**
     * @param array{int, string} ...$headings rank and text
     *
     * @return array<string, mixed>
     */
    private static function richText(array ...$headings): array
    {
        return ['type' => 'doc', 'content' => array_map(static fn(array $heading): array => ['type' => 'heading', 'attrs' => ['level' => $heading[0]], 'content' => [['type' => 'text', 'text' => $heading[1]]]], $headings)];
    }

    /**
     * @return list<string> e.g. "h2 Our beans"
     */
    private static function outline(string $html): array
    {
        preg_match_all('~<h([1-6])(?:\s[^>]*)?>(.*?)</h\1>~s', $html, $matches, \PREG_SET_ORDER);

        return array_map(static fn(array $match): string => 'h' . $match[1] . ' ' . strip_tags($match[2]), $matches);
    }

    private function renderer(): ComponentRenderer
    {
        return $this->get(ComponentRenderer::class);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function get(string $class): object
    {
        $service = $this->container->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
    }
}
