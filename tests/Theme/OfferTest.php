<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Theme;

use Frootbox\Core\Action\InvalidInputException;
use Frootbox\Core\Component\BlockSummary;
use Frootbox\Core\Component\ComponentLoader;
use Frootbox\Core\Component\Components;
use Frootbox\Core\Component\LayoutSummary;
use Frootbox\Core\Component\ListBlocks;
use Frootbox\Core\Component\ListLayouts;
use Frootbox\Core\Extension\Extensions;
use Frootbox\Core\Page\AddBlock;
use Frootbox\Core\Page\ContentBlocks;
use Frootbox\Core\Page\CopyBlock;
use Frootbox\Core\Page\CreatePage;
use Frootbox\Core\Page\MoveBlock;
use Frootbox\Core\Page\UpdatePage;
use Frootbox\Core\Tests\Support\FixtureSite;
use Frootbox\Core\Tests\Support\NotesTestCase;
use Frootbox\Core\Theme\Offer;
use Frootbox\Core\Theme\ThemeException;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(Offer::class)]
#[CoversClass(Components::class)]
#[CoversClass(ComponentLoader::class)]
final class OfferTest extends NotesTestCase
{
    public function testListsHiddenOnesAsNotOffered(): void
    {
        $blocks = array_column($this->runner()->ask(new ListBlocks()), null, 'name');
        $layouts = array_column($this->runner()->ask(new ListLayouts()), null, 'name');

        self::assertTrue($this->block($blocks, 'acme/base/Badge')->offered);
        self::assertTrue($this->block($blocks, 'acme/base/Columns')->offered);
        self::assertFalse($this->block($blocks, 'acme/base/Teaser')->offered, 'Not in "only" of the parent.');
        self::assertFalse($this->block($blocks, 'acme/news/List')->offered, 'Hidden although of a module.');
        self::assertFalse($this->layout($layouts, 'acme/base/Page')->offered);
        self::assertTrue($this->layout($layouts, 'acme/picky/Landing')->offered, 'A theme offers all of its own.');
    }

    public function testHiddenOnesStayOnPagesButCannotBeAddedAgain(): void
    {
        $this->assertRejected('/layout', fn() => $this->runner()->run(new CreatePage('acme/base/Page', ['de' => ['title' => 'Start']])));

        $content = ['areas' => ['main' => [['block' => 'acme/base/Teaser', 'fields' => ['title' => 'Alt']], ['block' => 'acme/base/Columns']]]];
        $page = $this->runner()->run(new CreatePage('acme/picky/Landing', ['de' => ['title' => 'Start', 'content' => $content]]));
        $saved = $page->draft->translations['de']->content ?? [];
        $teaser = ContentBlocks::idAt($saved, ['areas', 'main'], 0);
        $columns = ContentBlocks::idAt($saved, ['areas', 'main'], 1);
        self::assertNotNull($teaser);
        self::assertNotNull($columns);

        $this->assertRejected('/name', fn() => $this->runner()->run(new AddBlock($page->id, 'de', 'acme/base/Teaser', area: 'main')));
        $this->assertRejected('/name', fn() => $this->runner()->run(new CopyBlock($page->id, 'de', $teaser)));
        $this->assertRejected('/layout', fn() => $this->runner()->run(new UpdatePage($page->id, layout: 'acme/base/Page')));

        // Moving keeps it, and offered ones still go in.
        $this->runner()->run(new MoveBlock($page->id, 'de', $teaser, area: 'main', after: $columns));
        $this->runner()->run(new AddBlock($page->id, 'de', 'acme/base/Columns', area: 'main'));
        $this->runner()->run(new UpdatePage($page->id, layout: 'acme/picky/Landing'));
    }

    public function testChecksTheNames(): void
    {
        $this->expectException(ThemeException::class);
        $this->expectExceptionMessage('"blocks.hide" contains "Teaser", which is no full name such as frootbox/theme-base/Hero.');

        Offer::from(['hide' => ['Teaser']], 'blocks', 'theme');
    }

    public function testRejectsUnknownKeys(): void
    {
        $this->expectException(ThemeException::class);
        $this->expectExceptionMessage('"layouts" takes "only" and "hide"');

        Offer::from(['show' => []], 'layouts', 'theme');
    }

    protected function extensions(): Extensions
    {
        return FixtureSite::extensions('base', 'news', 'picky');
    }

    protected function config(): array
    {
        return ['theme' => 'acme/picky', 'languages' => ['de' => ['title' => 'Deutsch']]];
    }

    /**
     * @param array<string, BlockSummary> $blocks
     */
    private function block(array $blocks, string $name): BlockSummary
    {
        self::assertArrayHasKey($name, $blocks);

        return $blocks[$name];
    }

    /**
     * @param array<string, LayoutSummary> $layouts
     */
    private function layout(array $layouts, string $name): LayoutSummary
    {
        self::assertArrayHasKey($name, $layouts);

        return $layouts[$name];
    }

    private function assertRejected(string $pointer, callable $run): void
    {
        try {
            $run();
        } catch (InvalidInputException $exception) {
            self::assertArrayHasKey($pointer, $exception->errors, implode(' ', $exception->errors));

            return;
        }

        self::fail('The action ran.');
    }
}
