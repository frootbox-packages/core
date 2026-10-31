<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Page;

use Frootbox\Core\Action\AccessDeniedException;
use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\Channel;
use Frootbox\Core\Action\Context;
use Frootbox\Core\Action\InvalidInputException;
use Frootbox\Core\Action\Undo\UndoAction;
use Frootbox\Core\Auth\Actor;
use Frootbox\Core\Component\BlockSummary;
use Frootbox\Core\Component\ListBlocks;
use Frootbox\Core\Extension\Extensions;
use Frootbox\Core\Page\AddBlock;
use Frootbox\Core\Page\AddListEntry;
use Frootbox\Core\Page\BlockChanged;
use Frootbox\Core\Page\ContentBlocks;
use Frootbox\Core\Page\CopyBlock;
use Frootbox\Core\Page\CreatePage;
use Frootbox\Core\Page\ListEntries;
use Frootbox\Core\Page\MoveBlock;
use Frootbox\Core\Page\MoveListEntry;
use Frootbox\Core\Page\PageReader;
use Frootbox\Core\Page\PublishPages;
use Frootbox\Core\Page\RemoveBlock;
use Frootbox\Core\Page\RemoveListEntry;
use Frootbox\Core\Page\UpdateBlock;
use Frootbox\Core\Tests\Support\FixtureSite;
use Frootbox\Core\Tests\Support\NotesTestCase;
use Frootbox\Core\User\AssignRole;
use Frootbox\Core\User\CreateUser;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(AddBlock::class)]
#[CoversClass(MoveBlock::class)]
#[CoversClass(CopyBlock::class)]
#[CoversClass(RemoveBlock::class)]
#[CoversClass(UpdateBlock::class)]
#[CoversClass(AddListEntry::class)]
#[CoversClass(MoveListEntry::class)]
#[CoversClass(RemoveListEntry::class)]
#[CoversClass(ContentBlocks::class)]
#[CoversClass(ListEntries::class)]
#[CoversClass(BlockChanged::class)]
#[CoversClass(ListBlocks::class)]
#[CoversClass(BlockSummary::class)]
final class BlockActionsTest extends NotesTestCase
{
    private string $page;

    private string $columns;

    private string $fresh;

    private string $hero;

    private string $second;

    protected function setUp(): void
    {
        parent::setUp();

        $this->page = $this->runner()->run(new CreatePage('acme/base/Page', ['de' => ['title' => 'Start', 'content' => ['fields' => ['heading' => 'Willkommen'], 'areas' => ['main' => [
            ['block' => 'acme/base/Columns', 'areas' => ['left' => [['block' => 'acme/base/Teaser', 'fields' => ['title' => 'Frisch']]]]],
            ['block' => 'acme/site/Hero', 'fields' => ['slides' => [['title' => 'Eins'], ['title' => 'Zwei']]]],
            ['block' => 'acme/base/Teaser', 'fields' => ['title' => 'Zweiter']],
        ]]]]]))->id;
        $this->runner()->run(new PublishPages([$this->page]));

        $this->columns = $this->text('areas/main/0/id');
        $this->fresh = $this->text('areas/main/0/areas/left/0/id');
        $this->hero = $this->text('areas/main/1/id');
        $this->second = $this->text('areas/main/2/id');
    }

    public function testAddsBlocksWhereTheAreaTakesThem(): void
    {
        $added = $this->runner()->run(new AddBlock($this->page, 'de', 'acme/base/Teaser', 'main', after: $this->columns, variant: 'ImageLeft', options: ['rounded' => false], fields: ['title' => 'Neu']));

        self::assertNotNull($added->block);
        self::assertSame($added->block, $this->text('areas/main/1/id'));
        self::assertSame(['id' => $added->block, 'block' => 'acme/base/Teaser', 'variant' => 'ImageLeft', 'options' => ['rounded' => false], 'fields' => ['title' => 'Neu']], $this->valueAt('areas/main/1'));
        self::assertSame($this->lastEntry()->id, $added->entry);

        $first = $this->runner()->run(new AddBlock($this->page, 'de', 'acme/base/Teaser', 'main'));
        self::assertSame($first->block, $this->text('areas/main/0/id'), 'Without "after" it goes to the start.');

        $inside = $this->runner()->run(new AddBlock($this->page, 'de', 'acme/base/Badge', 'left', $this->columns, $this->fresh));
        self::assertSame($inside->block, $this->text('areas/main/1/areas/left/1/id'), 'A widget goes where the area names it.');

        $this->assertInvalid(['/area' => '"Badge" is a widget and only goes into areas that allow it by name.'], new AddBlock($this->page, 'de', 'acme/base/Badge', 'main'));
        $this->assertInvalid(['/area' => 'The area "Left" does not take "Hero".'], new AddBlock($this->page, 'de', 'acme/site/Hero', 'left', $this->columns));
        $this->assertInvalid(['/name' => 'There is no block "acme/base/Nope".'], new AddBlock($this->page, 'de', 'acme/base/Nope', 'main'));
        $this->assertInvalid(['/area' => 'There is no area "side" there.'], new AddBlock($this->page, 'de', 'acme/base/Teaser', 'side'));
        $this->assertInvalid(['/parent' => 'There is no block with this id in the content.'], new AddBlock($this->page, 'de', 'acme/base/Teaser', 'left', 'zzzzzzzz'));
        $this->assertInvalid(['/after' => 'There is no block with this id in the area.'], new AddBlock($this->page, 'de', 'acme/base/Teaser', 'main', after: $this->fresh));
        $this->assertInvalid(['/language' => 'The page has no translation in this language.'], new AddBlock($this->page, 'en', 'acme/base/Teaser', 'main'));
    }

    public function testMovesBlocksAlsoIntoOtherAreas(): void
    {
        $this->runner()->run(new MoveBlock($this->page, 'de', $this->second, 'main'));
        self::assertSame([$this->second, $this->columns, $this->hero], $this->ids('areas/main'));

        $this->runner()->run(new MoveBlock($this->page, 'de', $this->second, 'main', after: $this->hero));
        self::assertSame([$this->columns, $this->hero, $this->second], $this->ids('areas/main'), 'Down to the end.');

        $this->runner()->run(new MoveBlock($this->page, 'de', $this->second, 'left', $this->columns, $this->fresh));
        self::assertSame([$this->columns, $this->hero], $this->ids('areas/main'));
        self::assertSame([$this->fresh, $this->second], $this->ids('areas/main/0/areas/left'));
        self::assertSame('Zweiter', $this->valueAt('areas/main/0/areas/left/1/fields/title'), 'The block keeps its content.');

        $this->assertInvalid(['/parent' => 'A block cannot go into or next to itself.'], new MoveBlock($this->page, 'de', $this->columns, 'left', $this->columns));
        $this->assertInvalid(['/after' => 'A block cannot go into or next to itself.'], new MoveBlock($this->page, 'de', $this->columns, 'main', null, $this->fresh));
        $this->assertInvalid(['/area' => 'The area "Left" does not take "Hero".'], new MoveBlock($this->page, 'de', $this->hero, 'left', $this->columns));
        $this->assertInvalid(['/block' => 'There is no block with this id in the content.'], new MoveBlock($this->page, 'de', 'zzzzzzzz', 'main'));
    }

    public function testCopiesWithNewIdsAndRemoves(): void
    {
        $copy = $this->runner()->run(new CopyBlock($this->page, 'de', $this->columns));

        self::assertNotNull($copy->block);
        self::assertSame([$this->columns, $copy->block, $this->hero, $this->second], $this->ids('areas/main'));
        $inner = $this->text('areas/main/1/areas/left/0/id');
        self::assertNotSame($this->fresh, $inner, 'Blocks inside get new ids as well.');
        self::assertSame('Frisch', $this->valueAt('areas/main/1/areas/left/0/fields/title'));

        $removed = $this->runner()->run(new RemoveBlock($this->page, 'de', $this->columns));
        self::assertNull($removed->block);
        self::assertSame([$copy->block, $this->hero, $this->second], $this->ids('areas/main'));

        $this->runner()->run(new RemoveBlock($this->page, 'de', $inner));
        $copied = $this->valueAt('areas/main/0');
        self::assertIsArray($copied);
        $areas = $copied['areas'] ?? [];
        self::assertIsArray($areas);
        self::assertSame([], $areas['left'] ?? [], 'The area is empty.');
    }

    public function testChoosesVariantAndOptions(): void
    {
        $this->runner()->run(new UpdateBlock($this->page, 'de', $this->columns, 'Wide'));
        self::assertSame('Wide', $this->valueAt('areas/main/0/variant'));

        $this->runner()->run(new UpdateBlock($this->page, 'de', $this->columns, 'Default', ['gap' => true]));
        $columns = $this->valueAt('areas/main/0');
        self::assertIsArray($columns);
        self::assertArrayNotHasKey('variant', $columns, 'Default is the variant without a name.');
        self::assertSame(['gap' => true], $columns['options']);

        $this->runner()->run(new UpdateBlock($this->page, 'de', $this->columns, options: []));
        $columns = $this->valueAt('areas/main/0');
        self::assertIsArray($columns);
        self::assertArrayNotHasKey('options', $columns);
        self::assertSame([$this->fresh], $this->ids('areas/main/0/areas/left'), 'What is inside stays.');

        $this->assertInvalid(['/variant' => 'The block has no variant "Narrow".'], new UpdateBlock($this->page, 'de', $this->columns, 'Narrow'));

        try {
            $this->runner()->run(new UpdateBlock($this->page, 'de', $this->columns, options: ['gap' => 'yes']));
            self::fail('A wrong option was saved.');
        } catch (InvalidInputException $error) {
            self::assertArrayHasKey('/translations/de/content/areas/main/0/options/gap', $error->errors);
        }
    }

    public function testArrangesTheEntriesOfAList(): void
    {
        $this->runner()->run(new AddListEntry($this->page, 'de', 'slides', $this->hero, fields: ['title' => 'Drei']));
        $this->runner()->run(new AddListEntry($this->page, 'de', 'slides', $this->hero, 0));
        self::assertEquals([[], ['title' => 'Eins'], ['title' => 'Zwei'], ['title' => 'Drei']], $this->valueAt('areas/main/1/fields/slides'));

        // An empty entry can be changed later.
        $this->runner()->run(new MoveListEntry($this->page, 'de', 'slides/0', 3, $this->hero));
        self::assertEquals([['title' => 'Eins'], ['title' => 'Zwei'], ['title' => 'Drei'], []], $this->valueAt('areas/main/1/fields/slides'));

        $this->runner()->run(new RemoveListEntry($this->page, 'de', 'slides/3', $this->hero));
        $this->runner()->run(new MoveListEntry($this->page, 'de', 'slides/2', 0, $this->hero));
        self::assertSame([['title' => 'Drei'], ['title' => 'Eins'], ['title' => 'Zwei']], $this->valueAt('areas/main/1/fields/slides'));

        $this->assertInvalid(['/path' => 'The list has no entry 9.'], new RemoveListEntry($this->page, 'de', 'slides/9', $this->hero));
        $this->assertInvalid(['/to' => 'The list has no entry 3.'], new MoveListEntry($this->page, 'de', 'slides/0', 3, $this->hero));
        $this->assertInvalid(['/index' => 'The list has 3 entries.'], new AddListEntry($this->page, 'de', 'slides', $this->hero, 4));
        $this->assertInvalid(['/path' => '"intro" is no list.'], new AddListEntry($this->page, 'de', 'intro', $this->hero));

        $this->runner()->run(new AddListEntry($this->page, 'de', 'slides', $this->hero, fields: ['title' => 'Vier']));
        $this->runner()->run(new AddListEntry($this->page, 'de', 'slides', $this->hero, fields: ['title' => 'Fünf']));

        try {
            $this->runner()->run(new AddListEntry($this->page, 'de', 'slides', $this->hero, fields: ['title' => 'Sechs']));
            self::fail('The list grew beyond its maximum.');
        } catch (InvalidInputException $error) {
            self::assertArrayHasKey('/translations/de/content/areas/main/1/fields/slides', $error->errors);
        }
    }

    public function testIsUndoneAndLimitedToTheBranch(): void
    {
        $added = $this->runner()->run(new AddBlock($this->page, 'de', 'acme/base/Teaser', 'main'));
        self::assertNotNull($added->entry);
        $this->runner()->run(new UndoAction($added->entry));
        self::assertSame([$this->columns, $this->hero, $this->second], $this->ids('areas/main'));

        $other = $this->runner()->run(new CreatePage('acme/base/Page', ['de' => ['title' => 'Kontakt']], $this->page))->id;
        $user = $this->runner()->run(new CreateUser('jane@example.org', 'Jane', 'kaffeebohne42'))->user;
        $this->runner()->run(new AssignRole($user->id, 'editor', 'page:' . $other));
        $editor = new Context(Actor::user($user->id, $user->name), Channel::Api);

        $this->expectException(AccessDeniedException::class);
        $this->runner()->run(new RemoveBlock($this->page, 'de', $this->second), $editor);
    }

    public function testListsTheBlocks(): void
    {
        $blocks = [];

        foreach ($this->runner()->ask(new ListBlocks()) as $block) {
            $blocks[$block->name] = $block;
        }

        self::assertSame(['acme/base/Badge', 'acme/base/Columns', 'acme/site/Hero', 'acme/news/List', 'acme/base/Teaser'], array_keys($blocks));
        self::assertTrue($blocks['acme/base/Badge']->widget);
        self::assertSame(['left', 'right'], array_column(array_map(get_object_vars(...), $blocks['acme/base/Columns']->areas), 'name'));
        self::assertSame(['acme/base/Teaser', 'acme/news/List', 'acme/base/Badge'], $blocks['acme/base/Columns']->areas[0]->allow);
        self::assertSame('gap', $blocks['acme/base/Columns']->variants[0]->options[0]->name);
        self::assertSame(['Default', 'ImageLeft'], array_column(array_map(get_object_vars(...), $blocks['acme/base/Teaser']->variants), 'name'));
        self::assertSame([], $blocks['acme/base/Teaser']->variants[0]->options, 'The theme overrides Default without options.');
        self::assertTrue($blocks['acme/base/Teaser']->variants[1]->options[0]->default);
        self::assertSame('list', $blocks['acme/site/Hero']->fields[1]->type);
        self::assertSame(['title', 'link'], array_column(array_map(get_object_vars(...), $blocks['acme/site/Hero']->fields[1]->fields), 'name'));
    }

    protected function extensions(): Extensions
    {
        return FixtureSite::extensions('base', 'site', 'news');
    }

    protected function config(): array
    {
        return ['app' => ['url' => 'https://example.org'], 'theme' => 'acme/site', 'languages' => ['de' => ['title' => 'Deutsch']]];
    }

    /**
     * @param array<string, string> $errors
     * @param Action<mixed>         $action
     */
    private function assertInvalid(array $errors, Action $action): void
    {
        try {
            $this->runner()->run($action);
            self::fail('The action ran.');
        } catch (InvalidInputException $error) {
            self::assertSame($errors, $error->errors);
        }
    }

    /**
     * The ids of the blocks of an area.
     *
     * @return list<string>
     */
    private function ids(string $path): array
    {
        $blocks = $this->valueAt($path);
        self::assertIsArray($blocks);

        return array_values(array_map(static fn(mixed $block): string => \is_array($block) && \is_string($block['id'] ?? null) ? $block['id'] : '', $blocks));
    }

    /**
     * The value at a path such as "areas/main/0/id" in the content of the draft.
     */
    private function valueAt(string $path): mixed
    {
        $draft = $this->get(PageReader::class)->draft($this->page) ?? $this->get(PageReader::class)->published($this->page);
        self::assertNotNull($draft);
        $value = $draft->translations['de']->content;

        foreach (explode('/', $path) as $key) {
            self::assertIsArray($value, $path);
            self::assertArrayHasKey($key, $value, $path);
            $value = $value[$key];
        }

        return $value;
    }

    private function text(string $path): string
    {
        $value = $this->valueAt($path);
        self::assertIsString($value, $path);

        return $value;
    }
}
