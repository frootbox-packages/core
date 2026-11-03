<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Tag;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\InvalidInputException;
use Frootbox\Core\Category\ApplyPresets;
use Frootbox\Core\Category\Categories;
use Frootbox\Core\Category\CategoryEditor;
use Frootbox\Core\Category\CategoryGroups;
use Frootbox\Core\Category\CategoryItem;
use Frootbox\Core\Category\CreateCategory;
use Frootbox\Core\Category\ListCategories;
use Frootbox\Core\Config\ConfigException;
use Frootbox\Core\Extension\Extensions;
use Frootbox\Core\Plugin\CreateSpace;
use Frootbox\Core\ProjectPaths;
use Frootbox\Core\Revision\Revisions;
use Frootbox\Core\Tag\CreateTag;
use Frootbox\Core\Tag\DeleteTag;
use Frootbox\Core\Tag\ListTags;
use Frootbox\Core\Tag\MergeTags;
use Frootbox\Core\Tag\ObjectTags;
use Frootbox\Core\Tag\Tag;
use Frootbox\Core\Tag\TagEditor;
use Frootbox\Core\Tag\TagOptions;
use Frootbox\Core\Tag\TagPreset;
use Frootbox\Core\Tag\TagPresets;
use Frootbox\Core\Tag\Tags;
use Frootbox\Core\Tag\TagSummary;
use Frootbox\Core\Tag\Taxonomies;
use Frootbox\Core\Tag\UpdateTag;
use Frootbox\Core\Tests\Fixtures\Plugin\ItemPlugin;
use Frootbox\Core\Tests\Fixtures\Plugin\PublishItems;
use Frootbox\Core\Tests\Fixtures\Plugin\SaveItem;
use Frootbox\Core\Tests\Fixtures\Plugin\UnpublishItem;
use Frootbox\Core\Tests\Support\FixtureSite;
use Frootbox\Core\Tests\Support\NotesTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(TagEditor::class)]
#[CoversClass(ObjectTags::class)]
#[CoversClass(TagPresets::class)]
#[CoversClass(Tags::class)]
#[CoversClass(ListTags::class)]
#[CoversClass(Taxonomies::class)]
#[CoversClass(CategoryEditor::class)]
#[CoversClass(CategoryGroups::class)]
final class TagsTest extends NotesTestCase
{
    private const string ONE = '01JTAGITEM0000000000000001';

    private const string TWO = '01JTAGITEM0000000000000002';

    public function testFindsAWordByAnyLabelAndCreatesNewOnes(): void
    {
        $green = $this->runner()->run(new CreateTag(['de' => 'Nachhaltigkeit', 'en' => 'Sustainability']));
        self::assertSame('nachhaltigkeit', $green->key, 'The key from the first label.');

        $this->save(self::ONE, ['sustainability', 'Kaffee', $green->id, 'kaffee']);
        $draft = $this->draftTags(self::ONE);
        $coffee = $this->get(Tags::class)->byKey('kaffee') ?? self::fail('A new word becomes a tag.');
        self::assertSame([$green->id, $coffee->id], $draft, 'Each tag once, found by any label without regard to case.');
        self::assertSame(['de' => 'Kaffee'], $coffee->labels, 'In the default language.');

        $this->assertInvalid('/labels/en', new CreateTag(['en' => 'SUSTAINABILITY']), 'A label belongs to one tag.');
        $this->assertInvalid('/labels', new CreateTag([]));
        self::assertSame('featured', $this->runner()->run(new CreateTag([], 'featured', internal: true))->key, 'An internal tag needs no label.');
    }

    public function testLinksPublishedObjectsMergesAndDeletes(): void
    {
        $green = $this->runner()->run(new CreateTag(['de' => 'Nachhaltigkeit', 'en' => 'Sustainability']));
        $eco = $this->runner()->run(new CreateTag(['de' => 'Öko']));
        $hidden = $this->runner()->run(new CreateTag([], 'featured', internal: true));
        $this->save(self::ONE, [$green->id, $hidden->id]);
        $this->save(self::TWO, [$eco->id]);
        $objects = $this->get(ObjectTags::class);
        self::assertSame([], $objects->of(ItemPlugin::TYPE, [self::ONE]), 'Drafts keep their tags themselves.');

        $this->runner()->run(new PublishItems([self::ONE, self::TWO]));
        self::assertSame([['nachhaltigkeit', 'featured'], ['oeko']], array_values(array_map(static fn(array $tags): array => array_map(static fn(Tag $tag): string => $tag->key, $tags), $objects->of(ItemPlugin::TYPE, [self::ONE, self::TWO]))));

        $taxonomies = $this->get(Taxonomies::class);
        self::assertSame([['key' => 'nachhaltigkeit', 'label' => 'Sustainability', 'slug' => 'sustainability']], $taxonomies->tags(ItemPlugin::TYPE, self::ONE, ['en', 'de']), 'Visitors never see internal tags.');
        self::assertTrue($taxonomies->hasTag(ItemPlugin::TYPE, self::ONE, 'featured'), 'Templates can switch by them.');
        self::assertSame($green->id, $objects->bySlug('en', 'sustainability')?->id);
        self::assertSame($green->id, $objects->bySlug('de', 'nachhaltigkeit')?->id);
        self::assertNull($objects->bySlug('de', 'featured'), 'Never filtered by an internal one.');

        $this->runner()->run(new MergeTags($eco->id, $green->id));
        self::assertSame([self::ONE, self::TWO], array_keys(array_filter($objects->of(ItemPlugin::TYPE, [self::ONE, self::TWO]), static fn(array $tags): bool => [] !== array_filter($tags, static fn(Tag $tag): bool => $tag->id === $green->id))), 'The objects move along.');
        self::assertSame(['de' => 'Nachhaltigkeit', 'en' => 'Sustainability'], $this->get(Tags::class)->fetchById($green->id)?->labels);
        self::assertSame([$green->id], $objects->check('acme/plugin/item.save', ItemPlugin::TYPE, null, [$eco->id, 'öko']), 'A draft that names the merged one gets the other one.');

        $counts = array_map(static fn(TagSummary $tag): array => [$tag->key, $tag->count], $this->runner()->ask(new ListTags()));
        self::assertSame([['featured', 1], ['nachhaltigkeit', 2]], $counts, 'Merged ones are gone from the list.');

        $this->runner()->run(new UpdateTag($green->id, ['en' => ''], internal: false));
        self::assertSame(['de' => 'Nachhaltigkeit'], $this->get(Tags::class)->fetchById($green->id)?->labels);

        $this->runner()->run(new DeleteTag($green->id));
        $this->assertInvalid('/tags/0', new SaveItem(self::TWO, ['de' => 'Zwei'], ['de' => 'zwei'], tags: [$green->id]), 'A deleted tag is no new word.');
        self::assertSame([['featured']], array_values(array_map(static fn(array $tags): array => array_map(static fn(Tag $tag): string => $tag->key, $tags), $objects->of(ItemPlugin::TYPE, [self::ONE, self::TWO]))));

        $this->runner()->run(new UnpublishItem(self::ONE));
        self::assertSame([], $objects->of(ItemPlugin::TYPE, [self::ONE]));
    }

    public function testPresetsForEverywhereATypeAndASpaceAndClosedLists(): void
    {
        $space = $this->runner()->run(new CreateSpace(ItemPlugin::TYPE, 'Ausbildung'));
        $this->configure('tags.yaml', <<<'YAML'
            tags:
              - { de: Nachhaltigkeit, en: Sustainability }
              - { key: featured, internal: true }
            types:
              acme/plugin/item:
                tags: [{ de: Pflege }]
                spaces:
                  Ausbildung:
                    closed: true
                    tags: [Duales Studium]
              acme/other/thing: [Presse]
            YAML);
        $presets = $this->get(TagPresets::class);
        self::assertSame(['nachhaltigkeit', 'featured', 'pflege'], $this->keys($presets->for(ItemPlugin::TYPE)));
        self::assertSame(['nachhaltigkeit', 'featured', 'pflege', 'duales-studium'], $this->keys($presets->for(ItemPlugin::TYPE, $space->id)), 'The space by its name.');
        self::assertFalse($presets->closed(ItemPlugin::TYPE));
        self::assertTrue($presets->closed(ItemPlugin::TYPE, $space->id));
        self::assertNull($this->get(Tags::class)->byKey('pflege'), 'A preset is no tag until someone chooses it.');

        $this->save(self::ONE, ['Pflege', 'pflege', 'Kaffee']);
        self::assertSame(['pflege', 'kaffee'], array_map(fn(string $id): string => $this->get(Tags::class)->fetchById($id)->key ?? '', $this->draftTags(self::ONE)));

        $choices = $this->get(ObjectTags::class)->choices(ItemPlugin::TYPE, $space->id, []);
        self::assertTrue($choices['closed']);
        self::assertSame(['nachhaltigkeit', 'featured', 'pflege', 'duales-studium'], array_column($choices['options'], 'value'), 'A closed list offers only presets, by key until they exist.');
        self::assertSame(['Nachhaltigkeit', 'featured', 'Pflege', 'Duales Studium'], array_column($choices['options'], 'label'));

        try {
            $this->runner()->run(new SaveItem(self::TWO, ['de' => 'Zwei'], ['de' => 'zwei'], space: $space->id, tags: ['Duales Studium', 'Kaffee']));
            self::fail('A closed list takes only its presets.');
        } catch (InvalidInputException $exception) {
            self::assertSame(['/tags/1'], array_keys($exception->errors));
        }
    }

    public function testAClosedListRefusesTagsThatExistElsewhere(): void
    {
        $this->configure('tags.yaml', "types:\n  acme/plugin/item: { tags: [Pflege], closed: true }\n");
        $this->runner()->run(new CreateTag(['de' => 'Kaffee']));

        $this->assertInvalid('/tags/0', new SaveItem(self::ONE, ['de' => 'Eins'], ['de' => 'eins'], tags: ['kaffee']), 'Known, but not set out here.');
        $draft = $this->runner()->run(new SaveItem(self::ONE, ['de' => 'Eins'], ['de' => 'eins'], tags: ['pflege']));
        self::assertSame([$this->get(Tags::class)->byKey('pflege')?->id], $draft->data['tags'], 'A preset becomes a tag.');
    }

    public function testOffersTheTagsAsChoicesOfABlockAndShowsVisitorsTheirLabels(): void
    {
        $coffee = $this->runner()->run(new CreateTag(['de' => 'Kaffee', 'en' => 'Coffee']));
        $featured = $this->runner()->run(new CreateTag([], 'featured', true));

        self::assertSame([['value' => '', 'label' => 'Any'], ['value' => 'featured', 'label' => 'featured (internal)'], ['value' => 'kaffee', 'label' => 'Kaffee']], $this->runner()->ask(new TagOptions()));
        self::assertSame([['key' => 'kaffee', 'label' => 'Kaffee', 'slug' => 'kaffee']], $this->get(ObjectTags::class)->visible([$featured, $coffee], 'de'), 'Never an internal one.');
    }

    public function testRefusesUnknownKeysInTheConfiguration(): void
    {
        $this->configure('tags.yaml', "spaces:\n  Ausbildung: [Pflege]\n");

        $this->expectException(ConfigException::class);
        $this->get(TagPresets::class)->all();
    }

    public function testCategoriesComeFromPresetsOfTheConfiguration(): void
    {
        $this->configure('categories.yaml', <<<'YAML'
            groups:
              departments:
                title: Abteilung
                types: [acme/plugin/item]
                categories:
                  - { de: Kardiologie }
                  - { key: surgery, de: Chirurgie, children: [Unfallchirurgie] }
              acme/plugin/topics:
                categories: [Kurse]
            YAML);
        $this->runner()->run(new CreateCategory('acme/plugin/topics', ['de' => ['title' => 'Kurse']]));

        self::assertSame(3, $this->runner()->run(new ApplyPresets()), 'Kurse was there and takes its preset.');
        self::assertSame(0, $this->runner()->run(new ApplyPresets()), 'Only once.');
        $tree = array_map(static fn(CategoryItem $item): array => [(string) $item->title('de'), $item->depth], $this->runner()->ask(new ListCategories('departments')));
        self::assertSame([['Kardiologie', 0], ['Chirurgie', 0], ['Unfallchirurgie', 1]], $tree);
        self::assertSame('surgery', $this->get(Categories::class)->byPreset('departments', 'surgery')?->preset);
        self::assertSame('kurse', $this->get(Categories::class)->byPreset('acme/plugin/topics', 'kurse')?->preset, 'A group of an extension gets presets, too.');
    }

    protected function extensions(): Extensions
    {
        return FixtureSite::extensions('base', 'site', 'plugin');
    }

    protected function config(): array
    {
        return ['app' => ['name' => 'Testsite', 'url' => 'https://example.org'], 'theme' => 'acme/site', 'languages' => ['de' => ['title' => 'Deutsch'], 'en' => ['title' => 'English', 'fallback' => 'de']]];
    }

    /**
     * @param list<string> $tags
     */
    private function save(string $id, array $tags): void
    {
        $this->runner()->run(new SaveItem($id, ['de' => 'Ding ' . $id], ['de' => 'ding-' . strtolower(substr($id, -1))], tags: $tags));
    }

    /**
     * @return list<string>
     */
    private function draftTags(string $id): array
    {
        $tags = $this->get(Revisions::class)->draft(ItemPlugin::TYPE, $id)?->data['tags'] ?? [];

        return \is_array($tags) ? array_values(array_filter($tags, \is_string(...))) : [];
    }

    /**
     * @param list<TagPreset> $presets
     *
     * @return list<string>
     */
    private function keys(array $presets): array
    {
        return array_map(static fn(TagPreset $preset): string => $preset->key, $presets);
    }

    private function configure(string $file, string $yaml): void
    {
        file_put_contents($this->get(ProjectPaths::class)->config($file), $yaml);
    }

    /**
     * @param Action<mixed> $action
     */
    private function assertInvalid(string $pointer, Action $action, string $message = ''): void
    {
        try {
            $this->runner()->run($action);
            self::fail('' === $message ? 'Expected an error at ' . $pointer : $message);
        } catch (InvalidInputException $exception) {
            self::assertArrayHasKey($pointer, $exception->errors, $message);
        }
    }
}
