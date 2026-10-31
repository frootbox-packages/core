<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Page;

use Frootbox\Core\Action\InvalidInputException;
use Frootbox\Core\Action\Undo\UndoAction;
use Frootbox\Core\Action\UndoException;
use Frootbox\Core\Component\AreaSummary;
use Frootbox\Core\Component\ContentValidator;
use Frootbox\Core\Component\Instance;
use Frootbox\Core\Component\LayoutSummary;
use Frootbox\Core\Component\ListLayouts;
use Frootbox\Core\Component\VariantSummary;
use Frootbox\Core\Extension\Extensions;
use Frootbox\Core\Language\Language;
use Frootbox\Core\Language\Languages;
use Frootbox\Core\Page\CreatePage;
use Frootbox\Core\Page\DeletePage;
use Frootbox\Core\Page\DiscardPage;
use Frootbox\Core\Page\ListPageVersions;
use Frootbox\Core\Page\MovePage;
use Frootbox\Core\Page\Page;
use Frootbox\Core\Page\PageData;
use Frootbox\Core\Page\PageDetails;
use Frootbox\Core\Page\PageEditor;
use Frootbox\Core\Page\PageNode;
use Frootbox\Core\Page\PageReader;
use Frootbox\Core\Page\Pages;
use Frootbox\Core\Page\PageStatus;
use Frootbox\Core\Page\PageTranslation;
use Frootbox\Core\Page\PageTranslations;
use Frootbox\Core\Page\PageTreeNode;
use Frootbox\Core\Page\PageUndoCheck;
use Frootbox\Core\Page\PageVersion;
use Frootbox\Core\Page\PublishPages;
use Frootbox\Core\Page\RestorePage;
use Frootbox\Core\Page\ShowPage;
use Frootbox\Core\Page\ShowPageTree;
use Frootbox\Core\Page\Slug;
use Frootbox\Core\Page\TranslationData;
use Frootbox\Core\Page\UpdatePage;
use Frootbox\Core\Revision\Revision;
use Frootbox\Core\Revision\Revisions;
use Frootbox\Core\Revision\RevisionStore;
use Frootbox\Core\Support\SortKey;
use Frootbox\Core\Tests\Support\FixtureSite;
use Frootbox\Core\Tests\Support\NotesTestCase;
use Frootbox\Database\EntityNotFoundException;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(CreatePage::class)]
#[CoversClass(UpdatePage::class)]
#[CoversClass(MovePage::class)]
#[CoversClass(PublishPages::class)]
#[CoversClass(DiscardPage::class)]
#[CoversClass(DeletePage::class)]
#[CoversClass(RestorePage::class)]
#[CoversClass(ShowPage::class)]
#[CoversClass(ShowPageTree::class)]
#[CoversClass(ListPageVersions::class)]
#[CoversClass(PageEditor::class)]
#[CoversClass(PageReader::class)]
#[CoversClass(PageUndoCheck::class)]
#[CoversClass(PageData::class)]
#[CoversClass(TranslationData::class)]
#[CoversClass(PageDetails::class)]
#[CoversClass(PageNode::class)]
#[CoversClass(PageTreeNode::class)]
#[CoversClass(PageVersion::class)]
#[CoversClass(Page::class)]
#[CoversClass(PageTranslation::class)]
#[CoversClass(Pages::class)]
#[CoversClass(PageTranslations::class)]
#[CoversClass(Slug::class)]
#[CoversClass(ContentValidator::class)]
#[CoversClass(Instance::class)]
#[CoversClass(Revision::class)]
#[CoversClass(Revisions::class)]
#[CoversClass(RevisionStore::class)]
#[CoversClass(Languages::class)]
#[CoversClass(Language::class)]
#[CoversClass(SortKey::class)]
#[CoversClass(ListLayouts::class)]
#[CoversClass(LayoutSummary::class)]
#[CoversClass(VariantSummary::class)]
final class PagesTest extends NotesTestCase
{
    private const string LAYOUT = 'acme/base/Page';

    public function testCreatesAPageAsDraft(): void
    {
        $start = $this->createStart();

        self::assertSame(PageStatus::Draft, $start->status);
        self::assertNull($start->published);
        self::assertNull($start->version);
        self::assertNotNull($start->draft);
        self::assertSame('', $start->draft->translations['de']->slug, 'The start page has no slug.');

        $teaser = $this->blocks($start->draft, 'de')[0];
        self::assertSame('acme/base/Teaser', $teaser['block']);
        self::assertIsString($teaser['id']);
        self::assertMatchesRegularExpression('/' . ContentValidator::ID_PATTERN . '/', $teaser['id']);
        self::assertSame(0, $this->get(Pages::class)->count(), 'Drafts stay out of the pages table.');

        $tree = $this->runner()->ask(new ShowPageTree());
        self::assertCount(1, $tree);
        self::assertSame(['de' => 'Start'], $tree[0]->titles);
        self::assertEquals($start, $this->runner()->ask(new ShowPage($start->id)));
    }

    public function testMakesSlugsAndKeepsThemUniqueNextToEachOther(): void
    {
        $start = $this->createStart();
        $about = $this->createPage($start->id, 'Über uns & Team');

        self::assertSame('ueber-uns-team', $about->draft?->translations['de']->slug);
        self::assertSame('ueber-uns-team', Slug::fromTitle(' Über  uns & Team! '));
        self::assertSame('', Slug::fromTitle('!!!'));

        $this->assertInvalid(['/translations/de/slug' => \sprintf('is already used by the page "Über uns & Team" (%s) next to it.', $about->id)], fn() => $this->createPage($start->id, 'Ueber uns team'));
        $this->assertInvalid(['/translations/de/slug' => 'must be lower case a-z, 0-9 and hyphens, at most 100 characters, e.g. "about-us".'], fn() => $this->createPage($start->id, 'Kontakt', slug: 'Kontakt'));
        $this->assertInvalid(['/translations/de/slug' => 'is required: the title has no letters or digits to make one from.'], fn() => $this->createPage($start->id, '???'));

        // The same slug is fine below another parent and in another language.
        $this->createPage($about->id, 'Über uns & Team');
        $english = $this->runner()->run(new UpdatePage($about->id, ['en' => ['title' => 'Über uns & Team']]));
        self::assertSame(['de', 'en'], array_keys($english->draft->translations ?? []));
    }

    public function testThereIsOneStartPage(): void
    {
        $start = $this->createStart();

        $this->assertInvalid(['/parentId' => \sprintf('is required: there already is a start page (%s), and every other page has a parent.', $start->id)], fn() => $this->createStart());
        $this->assertInvalid(['/parentId' => 'There is no page 01HF7YAT000000000000000000.'], fn() => $this->createPage('01HF7YAT000000000000000000', 'Lost'));
        $this->assertInvalid(['/id' => 'The start page cannot be moved.'], fn() => $this->runner()->run(new MovePage($start->id, $start->id)));
    }

    public function testChecksTheContent(): void
    {
        $start = $this->createStart();
        $content = ['fields' => ['heading' => 'Hi', 'nope' => 1], 'options' => ['x' => 1], 'areas' => ['main' => [
            ['block' => 'acme/base/Columns', 'areas' => ['left' => [['block' => 'acme/site/Hero']]]],
            ['block' => 'acme/base/Teaser', 'variant' => 'ImageLeft', 'options' => ['rounded' => 'yes'], 'fields' => ['title' => str_repeat('x', 81)]],
            ['block' => 'acme/other/Promo'],
            ['block' => 'acme/news/List', 'id' => 'same0001'],
            ['block' => 'acme/news/List', 'id' => 'same0001'],
            ['block' => 'acme/news/List', 'id' => 'BAD'],
        ], 'side' => []]];

        $this->assertInvalid([
            '/translations/de/content/fields' => 'Unknown fields: nope',
            '/translations/de/content/options/x' => 'is not an option of this variant. Options: none.',
            '/translations/de/content/areas/side' => 'acme/base/Page has no area "side". Areas: main.',
            '/translations/de/content/areas/main/0/areas/left/0/block' => 'acme/site/Hero is not allowed in the area "left". Allowed: acme/base/Teaser, acme/news/List, acme/base/Badge.',
            '/translations/de/content/areas/main/1/options/rounded' => 'must be a valid boolean.',
            '/translations/de/content/areas/main/1/fields/title' => 'must be at most 80 characters long.',
            '/translations/de/content/areas/main/2/block' => 'there is no block "acme/other/Promo".',
            '/translations/de/content/areas/main/4/id' => '"same0001" is used by another block of this content.',
            '/translations/de/content/areas/main/5/id' => 'must be 8 characters a-z and 0-9, or be left out for a new block.',
            '/translations/fr' => '"fr" is not a language of the website. Languages: de, en.',
        ], fn() => $this->runner()->run(new UpdatePage($start->id, ['de' => ['content' => $content], 'fr' => ['title' => 'Bonjour']])));

        $this->assertInvalid(['/layout' => 'There is no layout "acme/base/Nope".'], fn() => $this->runner()->run(new UpdatePage($start->id, layout: 'acme/base/Nope')));
        $this->assertInvalid(['/variant' => 'acme/base/Page has no variant "Wide". Variants: Default.'], fn() => $this->runner()->run(new UpdatePage($start->id, variant: 'Wide')));
    }

    public function testDraftsMayBeUnfinishedButPublishingNeedsEverything(): void
    {
        $start = $this->createStart(content: ['areas' => ['main' => [['block' => 'acme/base/Teaser']]]]);

        $this->assertInvalid([
            '/ids/0/translations/de/content/fields/heading' => 'is required.',
            '/ids/0/translations/de/content/areas/main/0/fields/title' => 'is required.',
        ], fn() => $this->publish($start->id));
    }

    public function testPublishesTogetherAndKeepsVersions(): void
    {
        $start = $this->createStart();
        $about = $this->createPage($start->id, 'Über uns');

        $this->assertInvalid(['/ids/0' => \sprintf('The parent page %s is not published. Publish both together: {"ids": ["%s", "%s"]}.', $start->id, $start->id, $about->id)], fn() => $this->publish($about->id));
        $this->assertInvalid(['/ids' => 'needs at least one page id.'], fn() => $this->publish());

        [$published] = $this->publish($about->id, $start->id);
        self::assertSame(PageStatus::Published, $published->status);
        self::assertSame(1, $published->version);
        self::assertNull($published->draft);
        self::assertSame(2, $this->get(Pages::class)->count());
        self::assertSame('ueber-uns', $this->get(PageTranslations::class)->of($about->id)['de']->slug);
        $this->assertInvalid(['/ids/0' => \sprintf('The page %s has no draft to publish.', $about->id)], fn() => $this->publish($about->id));
        self::assertSame(['de' => '/ueber-uns', 'en' => '/en/ueber-uns'], $this->runner()->ask(new ShowPage($about->id))->urls, 'page.show says where visitors find the page, English with the German text.');
        self::assertSame([], $this->createPage($start->id, 'Neu')->urls);

        $changed = $this->runner()->run(new UpdatePage($about->id, ['de' => ['title' => 'Wir']]));
        self::assertSame(PageStatus::Changed, $changed->status);
        self::assertSame('Über uns', $changed->published?->translations['de']->title);
        self::assertSame('ueber-uns', $changed->draft?->translations['de']->slug, 'A new title keeps the slug.');

        $this->publish($about->id);
        $versions = $this->runner()->ask(new ListPageVersions($about->id));
        self::assertSame([2, 1], array_map(static fn(PageVersion $version): int => $version->version, $versions));
        self::assertSame([true, false], array_map(static fn(PageVersion $version): bool => $version->current, $versions));
        self::assertSame('Wir', $this->get(PageTranslations::class)->of($about->id)['de']->title);

        $restored = $this->runner()->run(new RestorePage($about->id, 1));
        self::assertSame('Über uns', $restored->draft?->translations['de']->title);
        $this->assertInvalid(['/version' => \sprintf('The page %s has no version 9.', $about->id)], fn() => $this->runner()->run(new RestorePage($about->id, 9)));
    }

    public function testRemovesATranslation(): void
    {
        $start = $this->createStart();
        $this->runner()->run(new UpdatePage($start->id, ['en' => ['title' => 'Home', 'content' => ['fields' => ['heading' => 'Welcome']]]]));
        $this->publish($start->id);
        self::assertCount(2, $this->get(PageTranslations::class)->of($start->id));

        $this->runner()->run(new UpdatePage($start->id, ['en' => null]));
        $this->publish($start->id);
        self::assertSame(['de'], array_keys($this->get(PageTranslations::class)->of($start->id)));

        $this->assertInvalid(['/translations' => 'needs at least one language, e.g. {"de": {"title": "Start"}}.'], fn() => $this->runner()->run(new UpdatePage($start->id, ['de' => null])));
    }

    public function testUndoesPublishing(): void
    {
        $start = $this->createStart();
        $this->publish($start->id);

        $this->runner()->run(new UndoAction($this->lastEntry()->id));

        self::assertSame(0, $this->get(Pages::class)->count());
        $details = $this->runner()->ask(new ShowPage($start->id));
        self::assertSame(PageStatus::Draft, $details->status);
        self::assertSame([], $this->runner()->ask(new ListPageVersions($start->id)));
    }

    public function testUndoKeepsOneDraftPerPage(): void
    {
        $start = $this->createStart();
        $this->publish($start->id);
        $this->runner()->run(new UpdatePage($start->id, ['de' => ['title' => 'Neu']]));
        $this->publish($start->id);
        $published = $this->lastEntry()->id;
        $this->runner()->run(new RestorePage($start->id, 1));
        $restored = $this->lastEntry()->id;

        $this->assertUndoBlocked(
            \sprintf('Action #%d cannot be undone: The page %s would have 2 draft revisions. Undo #%d (page.restore) first.', $published, $start->id, $restored),
            $published,
        );
        self::assertSame('Start', $this->runner()->ask(new ShowPage($start->id))->draft?->translations['de']->title, 'Nothing was undone.');

        $this->runner()->run(new UndoAction($restored));
        $this->runner()->run(new UndoAction($published));
        self::assertSame('Neu', $this->runner()->ask(new ShowPage($start->id))->draft->translations['de']->title);
    }

    public function testUndoKeepsParentsAndOneStartPage(): void
    {
        $start = $this->createStart();
        $about = $this->createPage($start->id, 'Über uns');
        $team = $this->createPage($about->id, 'Team');
        $this->publish($start->id, $about->id, $team->id);

        $this->runner()->run(new DeletePage($team->id));
        $teamDeleted = $this->lastEntry()->id;
        $this->runner()->run(new DeletePage($about->id));
        $aboutDeleted = $this->lastEntry()->id;

        $this->assertUndoBlocked(
            \sprintf('Action #%d cannot be undone: The page %s would have no parent: %s is gone. Undo #%d (page.delete) first.', $teamDeleted, $team->id, $about->id, $aboutDeleted),
            $teamDeleted,
        );

        $this->runner()->run(new UndoAction($aboutDeleted));
        $this->runner()->run(new UndoAction($teamDeleted));
        self::assertSame(['Team'], $this->titles($about->id));

    }

    public function testUndoKeepsSlugsUnique(): void
    {
        $start = $this->createStart();
        $draft = $this->createPage($start->id, 'Entwurf');
        $this->runner()->run(new DeletePage($draft->id));
        $deleted = $this->lastEntry()->id;
        $again = $this->createPage($start->id, 'Entwurf');
        $created = $this->lastEntry()->id;

        $this->assertUndoBlocked(
            \sprintf('Action #%d cannot be undone: The pages %s and %s would have the same slug "entwurf". Undo #%d (page.create) first.', $deleted, $draft->id, $again->id, $created),
            $deleted,
        );
    }

    public function testUndoKeepsOneStartPage(): void
    {
        $first = $this->createStart();
        $this->runner()->run(new DeletePage($first->id));
        $deleted = $this->lastEntry()->id;
        $second = $this->createStart();
        $created = $this->lastEntry()->id;

        $this->assertUndoBlocked(
            \sprintf('Action #%d cannot be undone: There would be 2 start pages: %s. Undo #%d (page.create) first.', $deleted, implode(', ', [$first->id, $second->id]), $created),
            $deleted,
        );
    }

    public function testMovesPages(): void
    {
        $start = $this->createStart();
        $a = $this->createPage($start->id, 'A');
        $b = $this->createPage($start->id, 'B');
        $c = $this->createPage($start->id, 'C');
        self::assertSame(['A', 'B', 'C'], $this->titles($start->id));

        $this->runner()->run(new MovePage($c->id, $start->id, 0));
        self::assertSame(['C', 'A', 'B'], $this->titles($start->id));

        $this->runner()->run(new MovePage($a->id, $start->id, 5));
        self::assertSame(['C', 'B', 'A'], $this->titles($start->id));

        $this->runner()->run(new MovePage($b->id, $a->id));
        self::assertSame(['C', 'A'], $this->titles($start->id));
        self::assertSame(['B'], $this->titles($a->id));

        $this->assertInvalid(['/parentId' => 'must not be the page itself or one of its subpages.'], fn() => $this->runner()->run(new MovePage($a->id, $b->id)));
        $this->assertInvalid(['/parentId' => 'must not be the page itself or one of its subpages.'], fn() => $this->runner()->run(new MovePage($a->id, $a->id)));

        // A published page moves in its draft only.
        $this->publish($start->id, $a->id, $b->id, $c->id);
        $this->runner()->run(new MovePage($b->id, $start->id, 0));
        self::assertSame(['B', 'C', 'A'], $this->titles($start->id));
        self::assertSame($a->id, $this->get(Pages::class)->getById($b->id)->parentId);
    }

    public function testDeletesAndRestoresPages(): void
    {
        $start = $this->createStart();
        $about = $this->createPage($start->id, 'Über uns');
        $this->publish($start->id, $about->id);

        $this->assertInvalid(['/id' => 'The page has 1 subpages. Delete or move them first.'], fn() => $this->runner()->run(new DeletePage($start->id)));

        self::assertTrue($this->runner()->run(new DeletePage($about->id)));
        self::assertNull($this->get(Pages::class)->fetchById($about->id));
        self::assertSame([], $this->get(PageTranslations::class)->of($about->id));
        self::assertSame([false], array_map(static fn(PageVersion $version): bool => $version->current, $this->runner()->ask(new ListPageVersions($about->id))));

        try {
            $this->runner()->ask(new ShowPage($about->id));
            self::fail('A deleted page was found.');
        } catch (EntityNotFoundException) {
        }

        $restored = $this->runner()->run(new RestorePage($about->id, 1));
        self::assertSame(PageStatus::Draft, $restored->status);

        $this->expectException(EntityNotFoundException::class);
        $this->runner()->run(new DeletePage('01HF7YAT000000000000000000'));
    }

    public function testDiscardsDrafts(): void
    {
        $start = $this->createStart();
        $this->publish($start->id);
        $this->runner()->run(new UpdatePage($start->id, ['de' => ['title' => 'Neu']]));

        $back = $this->runner()->run(new DiscardPage($start->id));
        self::assertNotNull($back);
        self::assertSame(PageStatus::Published, $back->status);
        self::assertSame('Start', $back->published?->translations['de']->title);

        $this->assertInvalid(['/id' => 'The page has no draft.'], fn() => $this->runner()->run(new DiscardPage($start->id)));

        $new = $this->createPage($start->id, 'Neu');
        $child = $this->createPage($new->id, 'Kind');
        $this->assertInvalid(['/id' => 'The page has 1 subpages. Delete or move them first.'], fn() => $this->runner()->run(new DiscardPage($new->id)));
        self::assertNull($this->runner()->run(new DiscardPage($child->id)));
        self::assertSame(['Neu'], $this->titles($start->id));
    }

    public function testTakesInputLikeTheApi(): void
    {
        $result = $this->runner()->call('page.create', [
            'layout' => self::LAYOUT,
            'translations' => ['de' => ['title' => 'Start', 'content' => ['fields' => ['heading' => 'Hallo']]]],
        ]);
        self::assertInstanceOf(PageDetails::class, $result);

        $this->assertInvalid(['/translations' => 'must be an object by language code, e.g. {"de": {"title": "Start"}}.'], fn() => $this->runner()->call('page.update', ['id' => $result->id, 'translations' => [['title' => 'x']]]));
        $this->assertInvalid([
            '/translations/de' => 'Unknown keys: name. Allowed: title, slug, content.',
            '/translations/en/title' => 'is required.',
            '/translations/en/content' => 'must be an object with "options", "fields" and "areas".',
        ], fn() => $this->runner()->call('page.update', ['id' => $result->id, 'translations' => ['de' => ['name' => 'x'], 'en' => ['content' => 'x']]]));
    }

    public function testListsTheLayouts(): void
    {
        self::assertEquals(
            [new LayoutSummary(self::LAYOUT, 'Page', '', [new VariantSummary('Default', 'Page', '')], [new AreaSummary('main', 'Main', '', null, null, null)])],
            $this->runner()->ask(new ListLayouts()),
        );
    }

    public function testReadsLanguagesFromTheConfig(): void
    {
        $languages = $this->get(Languages::class);

        self::assertSame(['de', 'en'], $languages->codes());
        self::assertSame('Deutsch', $languages->default()->title);
        self::assertSame('English', $languages->get('en')->title);
        self::assertCount(2, $languages->all());
    }

    protected function extensions(): Extensions
    {
        return FixtureSite::extensions();
    }

    protected function config(): array
    {
        return ['theme' => 'acme/site', 'languages' => ['de' => ['title' => 'Deutsch'], 'en' => ['title' => 'English']]];
    }

    /**
     * @param array<string, mixed> $content
     */
    private function createStart(array $content = ['fields' => ['heading' => 'Willkommen'], 'areas' => ['main' => [['block' => 'acme/base/Teaser', 'fields' => ['title' => 'Hi']]]]]): PageDetails
    {
        return $this->runner()->run(new CreatePage(self::LAYOUT, ['de' => ['title' => 'Start', 'content' => $content]]));
    }

    private function createPage(string $parentId, string $title, ?string $slug = null): PageDetails
    {
        $translation = ['title' => $title, 'content' => ['fields' => ['heading' => $title]]];

        return $this->runner()->run(new CreatePage(self::LAYOUT, ['de' => null === $slug ? $translation : [...$translation, 'slug' => $slug]], $parentId));
    }

    /**
     * @return list<PageDetails>
     */
    private function publish(string ...$ids): array
    {
        return $this->runner()->run(new PublishPages(array_values($ids)));
    }

    /**
     * Titles of the children of a page, in their order.
     *
     * @return list<string>
     */
    private function titles(string $parentId): array
    {
        $nodes = $this->get(PageReader::class)->nodes();

        return array_map(static fn(PageNode $node): string => $node->data->translations['de']->title, PageReader::children($nodes, $parentId));
    }

    /**
     * @return list<array<mixed>>
     */
    private function blocks(PageData $data, string $language): array
    {
        $main = $data->translations[$language]->content['areas'] ?? [];
        self::assertIsArray($main);
        self::assertIsArray($main['main'] ?? null);

        return array_values(array_filter($main['main'], \is_array(...)));
    }

    private function assertUndoBlocked(string $expected, int $entryId): void
    {
        try {
            $this->runner()->run(new UndoAction($entryId));
            self::fail('The undo was accepted.');
        } catch (UndoException $error) {
            self::assertSame($expected, $error->getMessage());
        }
    }

    /**
     * @param array<string, string> $expected
     * @param callable(): mixed     $call
     */
    private function assertInvalid(array $expected, callable $call): void
    {
        try {
            $call();
            self::fail('The input was accepted.');
        } catch (InvalidInputException $error) {
            self::assertEquals($expected, $error->errors);
        }
    }
}
