<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Plugin;

use DateTimeImmutable;
use Frootbox\Core\Action\InvalidInputException;
use Frootbox\Core\Category\CreateCategory;
use Frootbox\Core\Category\CreateGroup;
use Frootbox\Core\Category\DeleteCategory;
use Frootbox\Core\Category\UpdateCategory;
use Frootbox\Core\Config\Environment;
use Frootbox\Core\Extension\Extensions;
use Frootbox\Core\Http\Exception\HttpException;
use Frootbox\Core\Navigation\MenuItem;
use Frootbox\Core\Navigation\Menus;
use Frootbox\Core\Page\CreatePage;
use Frootbox\Core\Page\PageHandler;
use Frootbox\Core\Page\PageReader;
use Frootbox\Core\Page\PublishPages;
use Frootbox\Core\Page\RemoveBlock;
use Frootbox\Core\Page\UpdatePage;
use Frootbox\Core\Plugin\AssignPlugin;
use Frootbox\Core\Plugin\CategoryPages;
use Frootbox\Core\Plugin\CreateSpace;
use Frootbox\Core\Plugin\DeleteSpace;
use Frootbox\Core\Plugin\ListSpaces;
use Frootbox\Core\Plugin\PluginPages;
use Frootbox\Core\Plugin\Plugins;
use Frootbox\Core\Plugin\PluginSpaces;
use Frootbox\Core\Plugin\SpaceEditor;
use Frootbox\Core\Plugin\UpdateSpace;
use Frootbox\Core\Schedule\ScheduledTypes;
use Frootbox\Core\Schedule\Scheduler;
use Frootbox\Core\Tests\Fixtures\Plugin\ItemPlugin;
use Frootbox\Core\Tests\Fixtures\Plugin\PublishItems;
use Frootbox\Core\Tests\Fixtures\Plugin\SaveItem;
use Frootbox\Core\Tests\Fixtures\Plugin\UnpublishItem;
use Frootbox\Core\Tests\Support\FixtureSite;
use Frootbox\Core\Tests\Support\NotesTestCase;
use Frootbox\Core\Url\AddressSync;
use Frootbox\Core\Url\Target;
use Frootbox\Core\Url\UrlResolver;
use Frootbox\Core\Url\Urls;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Http\Message\ResponseInterface;

#[CoversClass(AssignPlugin::class)]
#[CoversClass(CreateSpace::class)]
#[CoversClass(UpdateSpace::class)]
#[CoversClass(DeleteSpace::class)]
#[CoversClass(ListSpaces::class)]
#[CoversClass(SpaceEditor::class)]
#[CoversClass(PluginSpaces::class)]
#[CoversClass(PluginPages::class)]
#[CoversClass(Plugins::class)]
#[CoversClass(AddressSync::class)]
#[CoversClass(CategoryPages::class)]
#[CoversClass(UrlResolver::class)]
#[CoversClass(Scheduler::class)]
#[CoversClass(Menus::class)]
#[CoversClass(ScheduledTypes::class)]
final class PluginsTest extends NotesTestCase
{
    private const string ITEM = '01JPLUGINITEM0000000000001';

    private string $start;

    private string $news;

    protected function setUp(): void
    {
        parent::setUp();

        $this->start = $this->page(null, 'Start');
        $this->news = $this->page($this->start, 'News');
        $this->runner()->run(new AssignPlugin($this->news, ItemPlugin::TYPE));
        $this->runner()->run(new PublishPages([$this->start, $this->news]));
        $this->runner()->run(new SaveItem(self::ITEM, ['de' => 'Neue Sorten'], ['de' => 'neue-sorten']));
        $this->runner()->run(new PublishItems([self::ITEM]));
    }

    public function testShowsAnItemBelowThePageOfItsSpace(): void
    {
        $response = $this->request('/news/neue-sorten');
        $html = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([['Start', false], ['News', false], ['Neue Sorten', true]], array_map(static fn(MenuItem $item): array => [$item->label, $item->current], $this->get(Menus::class)->breadcrumb()), 'The way ends with the item.');
        self::assertStringContainsString('<title>Neue Sorten – Testsite</title>', $html);
        self::assertStringContainsString('<link rel="canonical" href="https://example.org/news/neue-sorten">', $html);
        self::assertMatchesRegularExpression('~<article class="item"><h\d>Neue Sorten</h\d></article>~', $html, 'The view of the plugin shows the item.');
        self::assertStringContainsString('<ul class="items" data-space="default"></ul></main>', (string) $this->request('/news')->getBody(), 'The list at plugin() on the page itself.');
        self::assertStringNotContainsString('class="items"', (string) $this->request('/')->getBody(), 'plugin() is empty without a space.');
        self::assertSame('/news/neue-sorten', $this->get(Urls::class)->path('acme/plugin/item:' . self::ITEM, 'de'));
        self::assertSame(404, $this->statusOf('/news/alte-sorten'));
        self::assertSame(404, $this->statusOf('/neue-sorten'), 'Only below the page of the space.');
    }

    public function testCategoriesGetPagesOfTheirOwnWithTheSwitch(): void
    {
        $group = $this->runner()->run(new CreateGroup('Bereiche', [ItemPlugin::TYPE]))->id;
        $care = $this->runner()->run(new CreateCategory($group, ['de' => ['title' => 'Pflege', 'description' => 'Alles zur Pflege']]))->id;
        $intensive = $this->runner()->run(new CreateCategory($group, ['de' => ['title' => 'Intensivpflege']], $care))->id;
        // The same path as the item: the item wins (decision 0243).
        $clash = $this->runner()->run(new CreateCategory($group, ['de' => ['title' => 'Neue Sorten']]))->id;
        self::assertSame(404, $this->statusOf('/news/pflege'), 'Off unless the admin turns them on.');

        $this->runner()->run(new AssignPlugin($this->news, ItemPlugin::TYPE, categories: true));
        $this->runner()->run(new PublishPages([$this->news]));
        $html = (string) $this->request('/news/pflege/intensivpflege')->getBody();
        self::assertMatchesRegularExpression('~<article class="item"><h\d>Intensivpflege</h\d></article>~', $html, 'The view of the plugin on the page of the category.');
        self::assertStringContainsString('<title>Intensivpflege – Testsite</title>', $html);
        self::assertStringContainsString('<link rel="canonical" href="https://example.org/news/pflege/intensivpflege">', $html);
        self::assertSame([['Start', false], ['News', false], ['Pflege', false], ['Intensivpflege', true]], array_map(static fn(MenuItem $item): array => [$item->label, $item->current], $this->get(Menus::class)->breadcrumb()), 'The categories above on the way.');
        self::assertStringContainsString('<meta name="description" content="Alles zur Pflege">', (string) $this->request('/news/pflege')->getBody());
        self::assertSame('/news/pflege/intensivpflege', $this->get(Urls::class)->path(Target::category($this->news, $intensive), 'de'));
        self::assertSame('Intensivpflege', $this->get(CategoryPages::class)->title(Target::category($this->news, $intensive), 'de'), 'E.g. for the statistics.');
        self::assertNull($this->get(Urls::class)->path(Target::category($this->news, $clash), 'de'), 'The item has the path.');

        // A new slug redirects, a category that is gone is gone.
        $this->runner()->run(new UpdateCategory($care, ['de' => ['title' => 'Pflege', 'slug' => 'pflegedienst']]));
        self::assertSame(['/news/pflegedienst/intensivpflege', 301], $this->redirect('/news/pflege/intensivpflege'));
        $this->runner()->run(new DeleteCategory($intensive));
        self::assertSame(410, $this->statusOf('/news/pflegedienst/intensivpflege'));

        $this->runner()->run(new AssignPlugin($this->news, ItemPlugin::TYPE, categories: false));
        $this->runner()->run(new PublishPages([$this->news]));
        self::assertSame(410, $this->statusOf('/news/pflegedienst'), 'Switched off.');
    }

    public function testRedirectsWhenThePathOrThePageChanges(): void
    {
        $this->runner()->run(new SaveItem(self::ITEM, ['de' => 'Neue Sorten'], ['de' => 'sorten-2026']));
        $this->runner()->run(new PublishItems([self::ITEM]));
        self::assertSame(['/news/sorten-2026', 301], $this->redirect('/news/neue-sorten'));

        $this->runner()->run(new UpdatePage($this->news, ['de' => ['slug' => 'aktuelles']]));
        $this->runner()->run(new PublishPages([$this->news]));
        self::assertSame(200, $this->statusOf('/aktuelles/sorten-2026'), 'The paths follow the page at once.');
        self::assertSame(['/aktuelles/sorten-2026', 301], $this->redirect('/news/sorten-2026'));
        self::assertSame(['/aktuelles/sorten-2026', 301], $this->redirect('/news/neue-sorten'));

        $this->runner()->run(new UnpublishItem(self::ITEM));
        self::assertSame(410, $this->statusOf('/aktuelles/sorten-2026'), 'Offline: gone.');
    }

    public function testRealPagesComeFirstAndASpaceHasOnePage(): void
    {
        $real = $this->page($this->news, 'Neue Sorten');
        $this->runner()->run(new PublishPages([$real]));
        self::assertStringNotContainsString('class="item"', (string) $this->request('/news/neue-sorten')->getBody(), 'The real page.');

        $second = $this->page($this->start, 'Archiv');

        try {
            $this->runner()->run(new AssignPlugin($second, ItemPlugin::TYPE));
            self::fail('A space has one page.');
        } catch (InvalidInputException $error) {
            self::assertArrayHasKey('/space', $error->errors);
        }

        try {
            $this->runner()->run(new UpdatePage($second, ['de' => ['content' => ['areas' => ['main' => [['block' => 'acme/plugin/Items']]]]]]));
            self::fail('The view of a plugin goes into no area.');
        } catch (InvalidInputException $error) {
            self::assertArrayHasKey('/translations/de/content/areas/main/0/block', $error->errors);
        }
    }

    public function testASpaceHasItsOwnPage(): void
    {
        $training = $this->page($this->news, 'Ausbildung');
        $details = $this->runner()->run(new AssignPlugin($training, ItemPlugin::TYPE, newSpace: 'Ausbildung'));
        $space = $details->draft?->plugin->space ?? '';
        $this->runner()->run(new PublishPages([$training]));
        $this->runner()->run(new SaveItem('01JPLUGINITEM0000000000003', ['de' => 'Azubi'], ['de' => 'azubi'], space: $space));
        $this->runner()->run(new PublishItems(['01JPLUGINITEM0000000000003']));

        self::assertSame(200, $this->statusOf('/news/ausbildung/azubi'));
        self::assertSame(404, $this->statusOf('/news/azubi'), 'Not below the page of the default space.');
        self::assertStringContainsString('data-space="' . $space . '"', (string) $this->request('/news/ausbildung')->getBody());

        $spaces = $this->runner()->ask(new ListSpaces(ItemPlugin::TYPE));
        self::assertTrue($spaces->several);
        self::assertSame([['default', 'Items', $this->news], [$space, 'Ausbildung', $training]], array_map(static fn($item): array => [$item->id, $item->title, $item->pageId], $spaces->items));

        $this->runner()->run(new UpdateSpace($space, 'Azubis'));
        self::assertSame('Azubis', $this->get(PluginSpaces::class)->title(ItemPlugin::TYPE, $space));
    }

    public function testWithoutDetailsTheObjectsHaveNoAddresses(): void
    {
        $this->runner()->run(new AssignPlugin($this->news, ItemPlugin::TYPE, details: false));
        $this->runner()->run(new PublishPages([$this->news]));

        self::assertSame(410, $this->statusOf('/news/neue-sorten'));
        self::assertStringContainsString('class="items"', (string) $this->request('/news')->getBody(), 'The list stays.');
    }

    public function testSpacesWithEntriesOrAPageStay(): void
    {
        $space = $this->runner()->run(new CreateSpace(ItemPlugin::TYPE, 'Archiv'))->id;
        $this->runner()->run(new SaveItem('01JPLUGINITEM0000000000004', ['de' => 'Alt'], ['de' => 'alt'], space: $space));

        foreach (['default' => '/id', $space => '/id'] as $id => $pointer) {
            try {
                $this->runner()->run(new DeleteSpace($id));
                self::fail('The space stays.');
            } catch (InvalidInputException $error) {
                self::assertArrayHasKey($pointer, $error->errors);
            }
        }

        try {
            $this->runner()->run(new CreateSpace(ItemPlugin::TYPE, 'archiv'));
            self::fail('Names are unique.');
        } catch (InvalidInputException $error) {
            self::assertArrayHasKey('/title', $error->errors);
        }

        $empty = $this->runner()->run(new CreateSpace(ItemPlugin::TYPE, 'Leer'))->id;
        self::assertTrue($this->runner()->run(new DeleteSpace($empty)));
    }

    public function testTheViewOfThePluginStaysAndGoesWithThePlugin(): void
    {
        $view = $this->get(PageReader::class)->published($this->news)?->translations['de']->content['plugin'] ?? null;
        self::assertIsArray($view);
        self::assertSame('acme/plugin/Items', $view['block']);
        self::assertIsString($view['id']);

        try {
            $this->runner()->run(new RemoveBlock($this->news, 'de', $view['id']));
            self::fail('It stays.');
        } catch (InvalidInputException $error) {
            self::assertArrayHasKey('/block', $error->errors);
        }

        $this->runner()->run(new AssignPlugin($this->news));
        $this->runner()->run(new PublishPages([$this->news]));
        self::assertArrayNotHasKey('plugin', $this->get(PageReader::class)->published($this->news)?->translations['de']->content ?? []);
        self::assertSame(410, $this->statusOf('/news/neue-sorten'), 'Without the space no addresses.');
    }

    public function testTheSchedulerPublishesItemsOfPlugins(): void
    {
        $item = '01JPLUGINITEM0000000000002';
        $this->runner()->run(new SaveItem($item, ['de' => 'Später'], ['de' => 'spaeter'], new DateTimeImmutable('+1 hour')));
        self::assertSame(404, $this->statusOf('/news/spaeter'));

        $result = $this->get(Scheduler::class)->run(new DateTimeImmutable('+2 hours'));

        self::assertSame([$item], $result->published);
        self::assertSame([], $result->failed);
        self::assertSame(200, $this->statusOf('/news/spaeter'));
    }

    protected function environment(): Environment
    {
        return Environment::Production;
    }

    protected function extensions(): Extensions
    {
        return FixtureSite::extensions('base', 'site', 'plugin');
    }

    protected function config(): array
    {
        return ['app' => ['name' => 'Testsite', 'url' => 'https://example.org'], 'theme' => 'acme/site', 'languages' => ['de' => ['title' => 'Deutsch']]];
    }

    private function page(?string $parentId, string $title): string
    {
        $content = ['fields' => ['heading' => $title], 'areas' => ['main' => []]];

        return $this->runner()->run(new CreatePage('acme/base/Page', ['de' => ['title' => $title, 'content' => $content]], $parentId))->id;
    }

    private function request(string $path): ResponseInterface
    {
        return $this->get(PageHandler::class)->handle(new ServerRequest('GET', 'https://example.org' . $path));
    }

    private function statusOf(string $path): int
    {
        try {
            return $this->request($path)->getStatusCode();
        } catch (HttpException $error) {
            return $error->status;
        }
    }

    /**
     * @return array{string, int}
     */
    private function redirect(string $path): array
    {
        $response = $this->request($path);

        return [$response->getHeaderLine('Location'), $response->getStatusCode()];
    }
}
