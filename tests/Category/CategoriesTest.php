<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Category;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\InvalidInputException;
use Frootbox\Core\Category\Categories;
use Frootbox\Core\Category\Category;
use Frootbox\Core\Category\CategoryEditor;
use Frootbox\Core\Category\CategoryGroups;
use Frootbox\Core\Category\CategoryItem;
use Frootbox\Core\Category\CreateCategory;
use Frootbox\Core\Category\CreateGroup;
use Frootbox\Core\Category\DeleteCategory;
use Frootbox\Core\Category\DeleteGroup;
use Frootbox\Core\Category\GroupEditor;
use Frootbox\Core\Category\GroupSource;
use Frootbox\Core\Category\GroupSummary;
use Frootbox\Core\Category\ListCategories;
use Frootbox\Core\Category\ListGroups;
use Frootbox\Core\Category\MoveCategory;
use Frootbox\Core\Category\ObjectCategories;
use Frootbox\Core\Category\UpdateCategory;
use Frootbox\Core\Extension\Extensions;
use Frootbox\Core\ProjectPaths;
use Frootbox\Core\Tests\Fixtures\Plugin\ItemPlugin;
use Frootbox\Core\Tests\Fixtures\Plugin\PublishItems;
use Frootbox\Core\Tests\Fixtures\Plugin\SaveItem;
use Frootbox\Core\Tests\Fixtures\Plugin\UnpublishItem;
use Frootbox\Core\Tests\Support\FixtureSite;
use Frootbox\Core\Tests\Support\NotesTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(CategoryEditor::class)]
#[CoversClass(GroupEditor::class)]
#[CoversClass(CategoryGroups::class)]
#[CoversClass(Categories::class)]
#[CoversClass(ObjectCategories::class)]
#[CoversClass(ListCategories::class)]
#[CoversClass(ListGroups::class)]
final class CategoriesTest extends NotesTestCase
{
    private const string GROUP = 'acme/plugin/topics';

    private const string ONE = '01JCATEGORYITEM00000000001';

    private const string TWO = '01JCATEGORYITEM00000000002';

    public function testBuildsATreeAndMovesWithinIt(): void
    {
        $courses = $this->category('Kurse');
        $tastings = $this->category('Verkostungen');
        $barista = $this->category('Barista', $courses->id);
        $latte = $this->category('Latte Art', $courses->id);

        self::assertSame([['Kurse', 0], ['Barista', 1], ['Latte Art', 1], ['Verkostungen', 0]], $this->tree());

        $this->runner()->run(new MoveCategory($latte->id, $courses->id, 0));
        self::assertSame([['Kurse', 0], ['Latte Art', 1], ['Barista', 1], ['Verkostungen', 0]], $this->tree(), 'First among its siblings.');

        $this->runner()->run(new MoveCategory($courses->id, $tastings->id));
        self::assertSame([['Verkostungen', 0], ['Kurse', 1], ['Latte Art', 2], ['Barista', 2]], $this->tree(), 'With all below it.');
        self::assertSame([0], array_map(static fn(Category $category): int => $category->position, $this->get(Categories::class)->children(self::GROUP, null)), 'The old siblings close the gap.');

        $this->runner()->run(new MoveCategory($barista->id, null, 0));
        self::assertSame([['Barista', 0], ['Verkostungen', 0], ['Kurse', 1], ['Latte Art', 2]], $this->tree(), 'At the top again.');

        $this->assertInvalid('/parentId', new MoveCategory($tastings->id, $latte->id), 'Never below itself.');
        $this->assertInvalid('/group', new CreateCategory('acme/plugin/nothing', ['de' => ['title' => 'X']]));
    }

    public function testKeepsSlugsUniquePerGroupAndLanguage(): void
    {
        $courses = $this->category('Kurse für alle', description: 'Alles rund um Kaffee.');

        self::assertSame(['de' => ['title' => 'Kurse für alle', 'slug' => 'kurse-fuer-alle', 'description' => 'Alles rund um Kaffee.']], $courses->translations, 'The slug from the title.');
        $this->assertInvalid('/translations/de/slug', new CreateCategory(self::GROUP, ['de' => ['title' => 'Andere', 'slug' => 'kurse-fuer-alle']]));
        $this->assertInvalid('/translations/de/title', new CreateCategory(self::GROUP, ['de' => ['title' => '', 'slug' => 'leer']]));

        $updated = $this->runner()->run(new UpdateCategory($courses->id, ['de' => ['title' => 'Kurse', 'slug' => 'kurse', 'description' => '']]));
        self::assertSame(['de' => ['title' => 'Kurse', 'slug' => 'kurse']], $updated->translations);
    }

    public function testLinksPublishedObjectsAndFindsThemWithTheCategoriesBelow(): void
    {
        $courses = $this->category('Kurse');
        $barista = $this->category('Barista', $courses->id);
        $tastings = $this->category('Verkostungen');
        $objects = $this->get(ObjectCategories::class);

        self::assertSame([$tastings->id, $barista->id], $objects->check('acme/plugin/item.save', ItemPlugin::TYPE, [$tastings->id, $barista->id, $tastings->id]));

        try {
            $objects->check('acme/plugin/item.save', 'acme/other/thing', [$barista->id, 'nothing']);
            self::fail('A group applies to its types only.');
        } catch (InvalidInputException $exception) {
            self::assertSame(['/categories/0', '/categories/1'], array_keys($exception->errors));
        }

        $this->runner()->run(new SaveItem(self::ONE, ['de' => 'Eins'], ['de' => 'eins'], categories: [$tastings->id, $barista->id]));
        $this->runner()->run(new SaveItem(self::TWO, ['de' => 'Zwei'], ['de' => 'zwei'], categories: [$courses->id]));
        self::assertSame([], $objects->of(ItemPlugin::TYPE, [self::ONE]), 'Drafts keep their categories themselves.');
        $this->runner()->run(new PublishItems([self::ONE, self::TWO]));
        $categories = $objects->of(ItemPlugin::TYPE, [self::ONE, self::TWO, 'item-3']);
        self::assertSame([['Barista', 'Verkostungen'], ['Kurse']], array_map(static fn(array $list): array => array_map(static fn(Category $category): string => (string) $category->title('de'), $list), array_values($categories)), 'In the order of the tree.');

        [$found, $ids] = $objects->bySlug(ItemPlugin::TYPE, 'de', 'kurse') ?? self::fail('Found by its slug.');
        self::assertSame($courses->id, $found->id);
        self::assertSame([$courses->id, $barista->id], $ids);

        $this->runner()->run(new DeleteCategory($courses->id));
        self::assertSame([self::ONE => [$tastings->id]], array_map(static fn(array $list): array => array_map(static fn(Category $category): string => $category->id, $list), $objects->of(ItemPlugin::TYPE, [self::ONE, self::TWO])), 'Gone with all below it.');
        self::assertNull($this->get(Categories::class)->fetchById($barista->id));

        $this->runner()->run(new UnpublishItem(self::ONE));
        self::assertSame([], $objects->of(ItemPlugin::TYPE, [self::ONE]), 'Offline without categories.');
    }

    public function testHasGroupsOfExtensionsTheConfigurationAndAdmins(): void
    {
        file_put_contents($this->get(ProjectPaths::class)->config('categories.yaml'), "groups:\n  departments: { title: Abteilung, types: [acme/plugin/item] }\n");
        $group = $this->runner()->run(new CreateGroup('Region', [ItemPlugin::TYPE]));

        $groups = $this->runner()->ask(new ListGroups(ItemPlugin::TYPE));
        self::assertSame([
            [self::GROUP, 'Topics', GroupSource::Module],
            ['departments', 'Abteilung', GroupSource::Config],
            [$group->id, 'Region', GroupSource::Admin],
        ], array_map(static fn(GroupSummary $summary): array => [$summary->key, $summary->title, $summary->source], $groups));
        self::assertSame([['type' => ItemPlugin::TYPE, 'label' => 'Items']], $groups[2]->types);

        $this->assertInvalid('/title', new CreateGroup('abteilung', [ItemPlugin::TYPE]), 'Names are unique without regard to case.');
        $this->assertInvalid('/types/0', new CreateGroup('Ort', ['acme/other/thing']));

        $this->runner()->run(new CreateCategory($group->id, ['de' => ['title' => 'Nord']]));
        $this->assertInvalid('/id', new DeleteGroup($group->id), 'Not with categories.');

        $rules = $this->connection()->fetchOne('SELECT permissions FROM roles WHERE name = ?', ['editor']);
        self::assertIsString($rules);
        $rules = json_decode($rules, true);
        self::assertIsArray($rules);
        self::assertContains('category.*', $rules, 'Editors keep categories, admins the groups.');
    }

    protected function extensions(): Extensions
    {
        return FixtureSite::extensions('base', 'site', 'plugin');
    }

    protected function config(): array
    {
        return ['app' => ['name' => 'Testsite', 'url' => 'https://example.org'], 'theme' => 'acme/site', 'languages' => ['de' => ['title' => 'Deutsch']]];
    }

    private function category(string $title, ?string $parentId = null, string $description = ''): Category
    {
        return $this->runner()->run(new CreateCategory(self::GROUP, ['de' => ['title' => $title, 'description' => $description]], $parentId));
    }

    /**
     * @return list<array{string, int}>
     */
    private function tree(): array
    {
        return array_map(static fn(CategoryItem $item): array => [(string) $item->title('de'), $item->depth], $this->runner()->ask(new ListCategories(self::GROUP)));
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
