<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Page;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\InvalidInputException;
use Frootbox\Core\Component\ComponentKind;
use Frootbox\Core\Component\Components;
use Frootbox\Core\Component\ComponentUsage;
use Frootbox\Core\Component\ContentValidator;
use Frootbox\Core\Component\ListComponentUsages;
use Frootbox\Core\Component\UsingPage;
use Frootbox\Core\Extension\Extensions;
use Frootbox\Core\Page\AddBlock;
use Frootbox\Core\Page\CreatePage;
use Frootbox\Core\Page\EditMode;
use Frootbox\Core\Page\MoveBlock;
use Frootbox\Core\Page\PageData;
use Frootbox\Core\Page\PageEditor;
use Frootbox\Core\Page\PageReader;
use Frootbox\Core\Page\PublishPages;
use Frootbox\Core\Page\RemoveBlock;
use Frootbox\Core\Page\UpdatePage;
use Frootbox\Core\Tests\Support\FixtureSite;
use Frootbox\Core\Tests\Support\NotesTestCase;
use Frootbox\Core\View\ComponentRenderer;
use Frootbox\Core\View\EditMarkup;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Content an extension left behind when it was removed or updated
 * (decision 0276). The stored drafts stand in for content saved while
 * the extension was there.
 */
#[CoversClass(ContentValidator::class)]
#[CoversClass(PageEditor::class)]
#[CoversClass(Components::class)]
#[CoversClass(ComponentRenderer::class)]
#[CoversClass(EditMarkup::class)]
#[CoversClass(ListComponentUsages::class)]
#[CoversClass(ComponentUsage::class)]
#[CoversClass(UsingPage::class)]
final class OrphanedContentTest extends NotesTestCase
{
    private const array ORPHAN = ['id' => 'orphan01', 'block' => 'acme/gone/Promo', 'fields' => ['title' => 'Sale'], 'areas' => ['inner' => [['id' => 'inner001', 'block' => 'acme/base/Teaser', 'fields' => ['title' => 'Inside']]]]];

    private const array TEASER = ['id' => 'teaser01', 'block' => 'acme/base/Teaser', 'fields' => ['title' => 'Hi']];

    public function testAPageWithABlockThatIsGoneCanStillBeChangedAndPublished(): void
    {
        $id = $this->page(['fields' => ['heading' => 'Start'], 'areas' => ['main' => [self::ORPHAN, self::TEASER]]]);

        $this->runner()->run(new UpdatePage($id, ['de' => ['title' => 'Neu']]));
        $this->runner()->run(new MoveBlock($id, 'de', 'orphan01', 'main', after: 'teaser01'));
        $this->runner()->run(new PublishPages([$id]));
        $columns = $this->runner()->run(new AddBlock($id, 'de', 'acme/base/Columns', 'main'))->block;
        self::assertIsString($columns);
        $this->assertInvalid(['/area' => 'A block whose extension is missing only moves within its area.'], new MoveBlock($id, 'de', 'orphan01', 'left', $columns));

        $main = $this->main($this->get(PageReader::class)->published($id));
        $ids = array_map(static fn(array $block): string => \is_string($block['id'] ?? null) ? $block['id'] : '', $main);
        self::assertSame(['teaser01', 'orphan01'], array_values(array_intersect($ids, ['teaser01', 'orphan01'])), 'The orphan moved.');
        self::assertContains(self::ORPHAN, $main, 'It stays as it was.');
    }

    public function testAnOrphanCanBeRemovedButNotChangedOrAddedAgain(): void
    {
        $id = $this->page(['areas' => ['main' => [self::ORPHAN]]]);
        $changed = [...self::ORPHAN, 'fields' => ['title' => 'Other']];

        $this->assertInvalid(['/translations/de/content/areas/main/0/block' => 'there is no block "acme/gone/Promo".'], new UpdatePage($id, ['de' => ['content' => ['areas' => ['main' => [$changed]]]]]));
        $this->assertInvalid(['/translations/de/content/areas/main/1/block' => 'there is no block "acme/gone/Promo".'], new UpdatePage($id, ['de' => ['content' => ['areas' => ['main' => [self::ORPHAN, [...self::ORPHAN, 'id' => 'orphan02']]]]]]));
        $this->assertInvalid(['/translations/de/content/areas/main/0/block' => 'there is no block "acme/gone/Promo".'], new CreatePage('acme/base/Page', ['de' => ['title' => 'Neu', 'content' => ['areas' => ['main' => [self::ORPHAN]]]]], $id));

        $this->runner()->run(new RemoveBlock($id, 'de', 'orphan01'));
        self::assertSame([], $this->main($this->get(PageReader::class)->draft($id)));
    }

    public function testKeepsWhatAnUpdateTookAwayFromABlock(): void
    {
        $stale = [...self::TEASER, 'variant' => 'Gone', 'options' => ['shadow' => true], 'fields' => ['title' => 'Hi', 'subtitle' => 'Old'], 'areas' => ['aside' => [self::ORPHAN]]];
        $id = $this->page(['options' => ['wide' => true], 'fields' => ['heading' => 'Start', 'kicker' => 'Old'], 'areas' => ['main' => [$stale], 'sidebar' => []]]);

        $this->runner()->run(new UpdatePage($id, ['de' => ['title' => 'Neu']]));
        $this->runner()->run(new PublishPages([$id]));

        $content = $this->get(PageReader::class)->published($id)?->translations['de']->content;
        self::assertSame(['wide' => true], $content['options'] ?? null);
        self::assertSame(['heading' => 'Start', 'kicker' => 'Old'], $content['fields'] ?? null);
        self::assertEquals([$stale], $this->main($this->get(PageReader::class)->published($id)));

        $this->assertInvalid(
            ['/translations/de/content/areas/main/0/fields' => 'Unknown field: subtitle.'],
            new UpdatePage($id, ['de' => ['content' => ['areas' => ['main' => [[...$stale, 'fields' => ['title' => 'Hi', 'subtitle' => 'New']]]]]]]),
            partial: true,
        );
    }

    public function testKeepsTheViewOfAPluginThatIsGone(): void
    {
        $view = ['id' => 'plugin01', 'block' => 'acme/gone/ArticleList', 'options' => ['perPage' => 5]];
        $id = $this->page(['fields' => ['heading' => 'News'], 'plugin' => $view]);

        $this->runner()->run(new UpdatePage($id, ['de' => ['title' => 'Neu']]));
        $this->runner()->run(new PublishPages([$id]));

        self::assertSame($view, $this->get(PageReader::class)->published($id)?->translations['de']->content['plugin'] ?? null);
        $this->assertInvalid(['/translations/de/content/plugin' => 'must be the view of a plugin, e.g. {"block": "acme/news/ArticleList"}.'], new UpdatePage($id, ['de' => ['content' => ['fields' => ['heading' => 'News'], 'plugin' => [...$view, 'options' => ['perPage' => 9]]]]]));
    }

    public function testAPageWhoseLayoutIsGoneShowsTheFirstLayout(): void
    {
        $id = $this->page(['fields' => ['heading' => 'Start'], 'areas' => ['main' => [self::TEASER], 'wide' => [[...self::TEASER, 'id' => 'teaser02']]]], 'acme/gone/Wide', 'Dark');

        $this->runner()->run(new UpdatePage($id, ['de' => ['title' => 'Neu']]));
        $this->runner()->run(new PublishPages([$id]));
        $page = $this->get(PageReader::class)->published($id);

        self::assertSame('acme/gone/Wide', $page?->layout, 'The layout stays until someone chooses another.');
        self::assertSame('Dark', $page->variant);
        self::assertSame('acme/base/Page', $this->get(Components::class)->fallbackLayout()?->name);

        $html = $this->get(ComponentRenderer::class)->layout(['layout' => 'acme/gone/Wide', 'variant' => 'Dark', ...$page->translations['de']->content]);
        self::assertStringContainsString('<h1>Start</h1>', $html);
        self::assertStringContainsString('Hi', $html);

        $this->assertInvalid(['/layout' => 'There is no layout "acme/gone/Wide".'], new CreatePage('acme/gone/Wide', ['de' => ['title' => 'Neu']], $id));
    }

    public function testListsWhichPagesUseWhichBlocksAndLayouts(): void
    {
        $start = $this->page(['fields' => ['heading' => 'Start'], 'areas' => ['main' => [self::ORPHAN, self::TEASER]]]);
        $this->runner()->run(new PublishPages([$start]));
        $other = $this->runner()->run(new CreatePage('acme/base/Page', ['de' => ['title' => 'Aktuelles', 'content' => ['areas' => ['main' => [['block' => 'acme/news/List']]]]]], $start))->id;

        $usages = $this->runner()->ask(new ListComponentUsages());

        self::assertEquals([
            new ComponentUsage('acme/base/Teaser', ComponentKind::Block, 'acme/base', true, [new UsingPage($start, 'Start')]),
            new ComponentUsage('acme/gone/Promo', ComponentKind::Block, 'acme/gone', false, [new UsingPage($start, 'Start')]),
            new ComponentUsage('acme/news/List', ComponentKind::Block, 'acme/news', true, [new UsingPage($other, 'Aktuelles')]),
            new ComponentUsage('acme/base/Page', ComponentKind::Layout, 'acme/base', true, [new UsingPage($other, 'Aktuelles'), new UsingPage($start, 'Start')]),
        ], $usages);
        self::assertSame(['acme/gone/Promo'], array_column($this->runner()->ask(new ListComponentUsages(missing: true)), 'name'));
        self::assertSame(['acme/news/List'], array_column($this->runner()->ask(new ListComponentUsages('acme/news')), 'name'));
    }

    public function testTheEditModeShowsAPlaceholderForABlockThatIsGone(): void
    {
        $this->get(EditMode::class)->set(true, marking: true);

        $html = $this->get(ComponentRenderer::class)->layout(['layout' => 'acme/base/Page', 'fields' => ['heading' => 'Start'], 'areas' => ['main' => [self::ORPHAN]]]);

        self::assertStringContainsString(
            '<fb-missing data-fb-block="orphan01" data-fb-name="acme/gone/Promo" data-fb-title="Missing block" data-fb-area="main" data-fb-missing><strong>Missing block</strong> <code>acme/gone/Promo</code><br>Its extension is not installed. Remove the block or install the extension again.</fb-missing>',
            $html,
        );
    }

    protected function extensions(): Extensions
    {
        return FixtureSite::extensions();
    }

    protected function config(): array
    {
        return ['theme' => 'acme/site', 'languages' => ['de' => ['title' => 'Deutsch']]];
    }

    /**
     * A page whose draft was saved while the extensions of its content
     * were there.
     *
     * @param array<string, mixed> $content
     */
    private function page(array $content, string $layout = 'acme/base/Page', ?string $variant = null): string
    {
        $id = $this->runner()->run(new CreatePage('acme/base/Page', ['de' => ['title' => 'Start']]))->id;
        $draft = $this->get(PageReader::class)->draft($id);
        self::assertNotNull($draft);
        $translations = ['de' => $draft->translations['de']->withContent($content)];
        $data = new PageData($draft->parentId, $draft->position, $layout, $variant, $translations, $draft->excludeFromSearchEngines, $draft->excludeFromSiteSearch, $draft->plugin);
        // Straight into the table: the content is only valid with the extensions that are gone now.
        $this->connection()->update('revisions', ['data' => json_encode($data->toArray(), \JSON_THROW_ON_ERROR)], ['type' => PageReader::TYPE, 'entity_id' => $id, 'status' => 'draft']);

        return $id;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function main(?PageData $page): array
    {
        $areas = $page?->translations['de']->content['areas'] ?? [];
        $main = \is_array($areas) ? $areas['main'] ?? [] : [];
        self::assertIsArray($main);
        $blocks = [];

        foreach ($main as $block) {
            self::assertIsArray($block);
            $blocks[] = array_combine(array_map(strval(...), array_keys($block)), array_values($block));
        }

        return $blocks;
    }

    /**
     * @param array<string, string> $expected
     * @param Action<mixed>         $action
     */
    private function assertInvalid(array $expected, Action $action, bool $partial = false): void
    {
        try {
            $this->runner()->run($action);
            self::fail('The action was accepted.');
        } catch (InvalidInputException $error) {
            $partial ? self::assertNotSame([], array_intersect_key($error->errors, $expected), json_encode($error->errors, \JSON_THROW_ON_ERROR)) : self::assertSame($expected, $error->errors);
        }
    }
}
