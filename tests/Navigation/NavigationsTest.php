<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Navigation;

use Frootbox\Core\Action\InvalidInputException;
use Frootbox\Core\Action\Undo\UndoAction;
use Frootbox\Core\Action\UndoException;
use Frootbox\Core\Extension\Extensions;
use Frootbox\Core\Language\Languages;
use Frootbox\Core\Navigation\CopyNavigation;
use Frootbox\Core\Navigation\Definitions;
use Frootbox\Core\Navigation\DiscardNavigation;
use Frootbox\Core\Navigation\ItemChecker;
use Frootbox\Core\Navigation\ListNavigations;
use Frootbox\Core\Navigation\MenuItem;
use Frootbox\Core\Navigation\Menus;
use Frootbox\Core\Navigation\NavigationEditor;
use Frootbox\Core\Navigation\NavigationReader;
use Frootbox\Core\Navigation\Navigations;
use Frootbox\Core\Navigation\NavigationStatus;
use Frootbox\Core\Navigation\NavigationUndoCheck;
use Frootbox\Core\Navigation\PublishNavigations;
use Frootbox\Core\Navigation\ShowNavigation;
use Frootbox\Core\Navigation\UpdateNavigation;
use Frootbox\Core\Page\CreatePage;
use Frootbox\Core\Page\PageOutline;
use Frootbox\Core\Page\PublishPages;
use Frootbox\Core\Tests\Support\FixtureSite;
use Frootbox\Core\Tests\Support\NotesTestCase;
use Frootbox\Core\Url\Target;
use Frootbox\Core\Url\Urls;
use Frootbox\Core\View\Delivery;
use Frootbox\Core\View\ViewException;
use PHPUnit\Framework\Attributes\CoversClass;
use Twig\Environment;

#[CoversClass(Definitions::class)]
#[CoversClass(ItemChecker::class)]
#[CoversClass(NavigationEditor::class)]
#[CoversClass(NavigationReader::class)]
#[CoversClass(Navigations::class)]
#[CoversClass(NavigationUndoCheck::class)]
#[CoversClass(Menus::class)]
#[CoversClass(MenuItem::class)]
#[CoversClass(PageOutline::class)]
#[CoversClass(UpdateNavigation::class)]
#[CoversClass(PublishNavigations::class)]
#[CoversClass(DiscardNavigation::class)]
#[CoversClass(CopyNavigation::class)]
#[CoversClass(ShowNavigation::class)]
#[CoversClass(ListNavigations::class)]
final class NavigationsTest extends NotesTestCase
{
    private string $start;

    private string $about;

    private string $team;

    private string $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->start = $this->createPage(null, ['de' => 'Start', 'en' => 'Home']);
        $this->about = $this->createPage($this->start, ['de' => 'Über uns', 'en' => 'About us']);
        $this->team = $this->createPage($this->about, ['de' => 'Team']);
        $this->shop = $this->createPage($this->start, ['de' => 'Laden']);
        $this->runner()->run(new PublishPages([$this->start, $this->about, $this->team, $this->shop]));
    }

    public function testThemesDeclareNavigations(): void
    {
        $definitions = $this->get(Definitions::class)->all();

        self::assertSame(['main', 'footer'], array_keys($definitions));
        self::assertSame('Main menu', $definitions['main']->title, 'The child theme overrides its parent.');
        self::assertSame(2, $definitions['main']->depth);
        self::assertSame(['highlight'], array_keys($definitions['main']->options));
        self::assertSame(1, $definitions['footer']->depth);

        $list = $this->runner()->ask(new ListNavigations());
        self::assertSame([['main', NavigationStatus::Empty], ['footer', NavigationStatus::Empty]], array_map(static fn($details): array => [$details->name, $details->status], $list));
    }

    public function testSavesDraftsAndPublishes(): void
    {
        $details = $this->runner()->run(new UpdateNavigation('main', ['de' => [
            ['type' => 'target', 'target' => 'page:' . $this->about, 'children' => [
                ['type' => 'anchor', 'anchor' => 'kontakt', 'target' => 'page:' . $this->about, 'label' => 'Kontakt'],
            ]],
            ['type' => 'phone', 'phone' => '+49 40 123456', 'options' => ['highlight' => true]],
        ]]));

        self::assertSame(NavigationStatus::Draft, $details->status);
        $items = $details->draft['de'] ?? [];
        self::assertCount(2, $items);
        self::assertIsString($items[0]['id']);
        self::assertMatchesRegularExpression('/' . ItemChecker::ID_PATTERN . '/', $items[0]['id']);
        self::assertSame([], $this->get(Menus::class)->navigation('main'), 'Drafts are not shown.');

        [$published] = $this->runner()->run(new PublishNavigations(['main']));
        self::assertSame(NavigationStatus::Published, $published->status);
        self::assertSame(1, $published->version);
        self::assertSame($items, $published->published['de']);

        // Keeping the ids keeps them; removing a language removes its row on publishing.
        $this->runner()->run(new UpdateNavigation('main', ['en' => [['type' => 'url', 'url' => ' https://shop.example.com ', 'label' => 'Shop']]]));
        $changed = $this->runner()->run(new UpdateNavigation('main', ['de' => $items, 'en' => null]));
        self::assertSame(NavigationStatus::Changed, $changed->status);
        self::assertSame($items, $changed->draft['de'] ?? null);

        $discarded = $this->runner()->run(new DiscardNavigation('main'));
        self::assertSame(NavigationStatus::Published, $discarded->status);

        $copied = $this->runner()->run(new CopyNavigation('main', 'de', 'en'));
        $english = $copied->draft['en'] ?? [];
        $children = NavigationReader::list($english[0]['children'] ?? null);
        self::assertSame([['id' => $children[0]['id'] ?? null, 'type' => 'anchor', 'label' => 'Kontakt', 'anchor' => 'kontakt', 'target' => 'page:' . $this->about]], $children);
        self::assertNotSame($items[0]['id'], $english[0]['id'], 'A copy gets new ids.');

        $this->runner()->run(new PublishNavigations(['main']));
        self::assertSame(['de', 'en'], array_keys($this->get(Navigations::class)->of('main')));
        $this->runner()->run(new UpdateNavigation('main', ['en' => null]));
        $this->runner()->run(new PublishNavigations(['main']));
        self::assertSame(['de'], array_keys($this->get(Navigations::class)->of('main')));
        self::assertSame(3, $this->runner()->ask(new ShowNavigation('main'))->version);
    }

    public function testChecksTheItems(): void
    {
        $this->assertInvalid(['/name' => 'The theme has no navigation "side". Navigations: main, footer.'], new UpdateNavigation('side', ['de' => []]));
        $this->assertInvalid(['/items' => 'must be an object by language code, e.g. {"de": [{"type": "url", "url": "https://…", "label": "Shop"}]}.'], new UpdateNavigation('main', [[]]));
        $this->assertInvalid(['/items/fr' => '"fr" is not a language of the website. Languages: de, en.'], new UpdateNavigation('main', ['fr' => []]));

        $this->assertInvalid([
            '/items/de/0/type' => 'must be one of target, url, anchor, phone, email, heading, pages.',
            '/items/de/1/target' => 'There is no page 01ARZ3NDEKTSV4RRFFQ69G5FAV.',
            '/items/de/2/label' => 'must be a text of 1 to 200 characters, it names the item.',
            '/items/de/2/url' => 'must use one of the protocols http, https, mailto, tel',
            '/items/de/3/anchor' => 'must be the id of an element without "#", e.g. "contact".',
            '/items/de/4/phone' => 'must be a phone number such as "+49 40 123456".',
            '/items/de/5/email' => 'must be an e-mail address.',
            '/items/de/6' => 'Unknown keys for "heading": url. Allowed: id, type, label, newWindow, options, children.',
            '/items/de/7/depth' => 'must be a whole number from 1 to 5.',
            '/items/de/7/children' => 'are the subpages of the page; "pages" has no own children.',
            '/items/de/8/options/highlight' => 'is not a valid value for "As button".',
            '/items/de/8/options/size' => 'The navigation "main" has no option "size". Options: highlight.',
            '/items/de/9/id' => 'must be 8 characters a-z and 0-9, unique in the navigation, or be left out for a new item.',
            '/items/de/9/children/0/children' => 'The navigation "main" has 2 level(s), these items are deeper.',
            '/items/de/10' => 'must be an object with "type".',
        ], new UpdateNavigation('main', ['de' => [
            ['type' => 'job'],
            ['type' => 'target', 'target' => 'page:01ARZ3NDEKTSV4RRFFQ69G5FAV'],
            ['type' => 'url', 'url' => 'javascript:alert(1)'],
            ['type' => 'anchor', 'anchor' => '#kontakt', 'label' => 'Kontakt'],
            ['type' => 'phone', 'phone' => 'call me'],
            ['type' => 'email', 'email' => 'info@'],
            ['type' => 'heading', 'label' => 'Mehr', 'url' => '/x'],
            ['type' => 'pages', 'target' => 'page:' . $this->start, 'depth' => 9, 'children' => []],
            ['id' => 'abcdefgh', 'type' => 'email', 'email' => 'info@example.com', 'options' => ['highlight' => 'ja', 'size' => 2]],
            ['id' => 'abcdefgh', 'type' => 'heading', 'label' => 'Tief', 'children' => [['type' => 'heading', 'label' => 'Tiefer', 'children' => [['type' => 'email', 'email' => 'a@example.com']]]]],
            ['phone'],
        ]]));
    }

    public function testBuildsTheMenuForTheVisitor(): void
    {
        $this->runner()->run(new UpdateNavigation('main', ['de' => [
            ['type' => 'pages', 'target' => 'page:' . $this->start, 'depth' => 2, 'options' => ['highlight' => true]],
            ['type' => 'heading', 'label' => 'Kontakt', 'children' => [
                ['type' => 'phone', 'phone' => '+49 (40) 123 456'],
                ['type' => 'email', 'email' => 'info@example.com', 'label' => 'Schreib uns'],
                ['type' => 'anchor', 'anchor' => 'karte', 'label' => 'Karte'],
            ]],
            ['type' => 'heading', 'label' => 'Leer', 'children' => []],
            ['type' => 'url', 'url' => 'https://example.com', 'label' => 'Extern', 'newWindow' => true],
        ]]));
        $this->runner()->run(new UpdateNavigation('footer', ['de' => [
            ['type' => 'target', 'target' => 'page:' . $this->team],
            ['type' => 'target', 'target' => 'page:' . $this->shop, 'label' => 'Unser Laden'],
            ['type' => 'anchor', 'target' => 'page:' . $this->about, 'anchor' => 'team', 'label' => 'Zum Team'],
        ]]));
        $this->runner()->run(new PublishNavigations(['main', 'footer']));

        $this->visit('de', $this->team);
        $main = $this->get(Menus::class)->navigation('main');

        self::assertSame(['Über uns', 'Laden', 'Kontakt', 'Extern'], self::labels($main), 'An empty heading drops out.');
        self::assertSame(['/ueber-uns', '/laden'], [$main[0]->url, $main[1]->url]);
        self::assertTrue($main[0]->active);
        self::assertFalse($main[0]->current);
        self::assertSame(['Team'], self::labels($main[0]->children));
        self::assertTrue($main[0]->children[0]->current);
        self::assertSame(['highlight' => true], $main[0]->options);
        self::assertFalse($main[1]->active);
        self::assertSame(['tel:+4940123456', 'mailto:info@example.com', '#karte'], array_map(static fn(MenuItem $item): ?string => $item->url, $main[2]->children));
        self::assertSame(['+49 (40) 123 456', 'Schreib uns', 'Karte'], self::labels($main[2]->children));
        self::assertNull($main[2]->url);
        self::assertSame(['highlight' => false], $main[3]->options, 'Options come with their defaults.');
        self::assertTrue($main[3]->newWindow);

        // English falls back to the German tree, with English URLs and titles.
        $this->visit('en', $this->about);
        $english = $this->get(Menus::class)->navigation('main');
        self::assertSame(['About us', 'Laden', 'Kontakt', 'Extern'], self::labels($english));
        self::assertSame(['/en/about-us', '/en/laden'], [$english[0]->url, $english[1]->url]);
        self::assertTrue($english[0]->current);
        self::assertSame(['Team'], self::labels($english[0]->children), 'Without an English title the fallback title.');

        $footer = $this->get(Menus::class)->navigation('footer');
        self::assertSame(['Team', 'Unser Laden', 'Zum Team'], self::labels($footer));
        self::assertSame('/en/about-us#team', $footer[2]->url);
        self::assertFalse($footer[2]->current, 'An anchor is never the current page.');

        $this->expectException(ViewException::class);
        $this->get(Menus::class)->navigation('side');
    }

    public function testRendersInTemplates(): void
    {
        $this->runner()->run(new UpdateNavigation('main', ['de' => [['type' => 'pages', 'target' => 'page:' . $this->start]]]));
        $this->runner()->run(new PublishNavigations(['main']));
        $this->visit('de', $this->team);

        $twig = $this->get(Environment::class)->createTemplate(
            '{% for item in navigation("main") %}<a href="{{ item.url }}"{% if item.active %} class="active"{% endif %}>{{ item.label }}</a>{% endfor %}'
            . '|{% for item in breadcrumb() %}{{ item.label }}{% if item.current %}!{% endif %}/{% endfor %}',
        );

        self::assertSame('<a href="/ueber-uns" class="active">Über uns</a><a href="/laden">Laden</a>|Start/Über uns/Team!/', $twig->render());
    }

    public function testUndoKeepsOneDraft(): void
    {
        $this->runner()->run(new UpdateNavigation('footer', ['de' => [['type' => 'email', 'email' => 'a@example.com']]]));
        $this->runner()->run(new PublishNavigations(['footer']));
        $this->runner()->run(new UpdateNavigation('footer', ['de' => [['type' => 'email', 'email' => 'b@example.com']]]));
        $this->runner()->run(new PublishNavigations(['footer']));
        $published = $this->lastEntry()->id;
        $this->runner()->run(new UpdateNavigation('footer', ['de' => [['type' => 'email', 'email' => 'c@example.com']]]));
        $updated = $this->lastEntry()->id;

        try {
            $this->runner()->run(new UndoAction($published));
            self::fail('The undo was accepted.');
        } catch (UndoException $error) {
            self::assertSame(\sprintf('Action #%d cannot be undone: The navigation "footer" would have 2 draft revisions. Undo #%d (navigation.update) first.', $published, $updated), $error->getMessage());
        }

        $this->runner()->run(new UndoAction($updated));
        $this->runner()->run(new UndoAction($published));
        $details = $this->runner()->ask(new ShowNavigation('footer'));
        self::assertSame('a@example.com', $details->published['de'][0]['email'] ?? null);
        self::assertSame('b@example.com', $details->draft['de'][0]['email'] ?? null);
    }

    protected function extensions(): Extensions
    {
        return FixtureSite::extensions('base', 'site');
    }

    protected function config(): array
    {
        return [
            'app' => ['name' => 'Testsite', 'url' => 'https://example.org'],
            'theme' => 'acme/site',
            'languages' => ['de' => ['title' => 'Deutsch'], 'en' => ['title' => 'English']],
        ];
    }

    /**
     * @param list<MenuItem> $items
     *
     * @return list<string>
     */
    private static function labels(array $items): array
    {
        return array_map(static fn(MenuItem $item): string => $item->label, $items);
    }

    private function visit(string $language, string $pageId): void
    {
        $this->get(Urls::class)->clear();
        $this->get(PageOutline::class)->clear();
        $this->get(Delivery::class)->set($this->get(Languages::class)->get($language), target: Target::page($pageId));
    }

    /**
     * @param array<string, string> $expected
     */
    private function assertInvalid(array $expected, UpdateNavigation $action): void
    {
        try {
            $this->runner()->run($action);
            self::fail('The input was accepted.');
        } catch (InvalidInputException $error) {
            self::assertEquals($expected, $error->errors);
        }
    }

    /**
     * @param array<string, string> $titles by language
     */
    private function createPage(?string $parentId, array $titles): string
    {
        $translations = array_map(static fn(string $title): array => ['title' => $title, 'content' => ['fields' => ['heading' => $title]]], $titles);

        return $this->runner()->run(new CreatePage('acme/base/Page', $translations, $parentId))->id;
    }
}
