<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Site;

use DateTimeImmutable;
use Frootbox\Core\Action\AccessDeniedException;
use Frootbox\Core\Action\Channel;
use Frootbox\Core\Action\ConflictException;
use Frootbox\Core\Action\Context;
use Frootbox\Core\Action\InvalidInputException;
use Frootbox\Core\Action\Undo\UndoAction;
use Frootbox\Core\Auth\AccessToken;
use Frootbox\Core\Auth\Actor;
use Frootbox\Core\Auth\Authentication;
use Frootbox\Core\Auth\TokenType;
use Frootbox\Core\Cache\PageCache;
use Frootbox\Core\Component\FieldSummary;
use Frootbox\Core\Config\Environment;
use Frootbox\Core\Extension\Extensions;
use Frootbox\Core\Page\CreatePage;
use Frootbox\Core\Page\EditMode;
use Frootbox\Core\Page\PageHandler;
use Frootbox\Core\Page\PageOutline;
use Frootbox\Core\Page\PublishPages;
use Frootbox\Core\Site\DiscardSite;
use Frootbox\Core\Site\ListSiteVersions;
use Frootbox\Core\Site\PublishSite;
use Frootbox\Core\Site\RestoreSite;
use Frootbox\Core\Site\ShowSite;
use Frootbox\Core\Site\SiteEditor;
use Frootbox\Core\Site\SiteFields;
use Frootbox\Core\Site\UpdateSite;
use Frootbox\Core\Site\UpdateSiteField;
use Frootbox\Core\Tests\Support\FixtureSite;
use Frootbox\Core\Tests\Support\NotesTestCase;
use Frootbox\Core\Url\Urls;
use Frootbox\Core\User\AssignRole;
use Frootbox\Core\User\CreateUser;
use Frootbox\Core\View\EditMarkup;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Http\Message\ResponseInterface;

#[CoversClass(SiteFields::class)]
#[CoversClass(SiteEditor::class)]
#[CoversClass(ShowSite::class)]
#[CoversClass(UpdateSite::class)]
#[CoversClass(UpdateSiteField::class)]
#[CoversClass(PublishSite::class)]
#[CoversClass(DiscardSite::class)]
#[CoversClass(ListSiteVersions::class)]
#[CoversClass(RestoreSite::class)]
#[CoversClass(EditMode::class)]
#[CoversClass(EditMarkup::class)]
final class SiteTest extends NotesTestCase
{
    public function testListsTheFieldsTheThemeDescribes(): void
    {
        $site = $this->runner()->ask(new ShowSite());

        self::assertSame('acme/site', $site->theme);
        self::assertFalse($site->draft);
        self::assertNull($site->version);
        self::assertSame(['brand', 'address', 'links', 'logo'], array_map(static fn(FieldSummary $field): string => $field->name, $site->fields));
        self::assertSame(['Name', 'Address'], [$site->fields[0]->label, $site->fields[1]->label]);
        self::assertSame(['de' => [], 'en' => []], $site->values);
    }

    public function testChangesTheDraftAndPublishesIt(): void
    {
        $site = $this->runner()->run(new UpdateSite('de', ['brand' => 'Bohnenglück', 'address' => 'Hafenstraße 12']));

        self::assertTrue($site->draft);
        self::assertSame(['brand' => 'Bohnenglück', 'address' => 'Hafenstraße 12'], $site->values['de']);
        self::assertSame($site->values['de'], $site->values['en'], 'English shows the German values until it has its own.');

        $fields = $this->get(SiteFields::class);
        self::assertSame([], $fields->values('de', false), 'Visitors see nothing before the publication.');
        self::assertSame('Bohnenglück', $fields->values('de', true)['brand']);

        $site = $this->runner()->run(new PublishSite());
        self::assertFalse($site->draft);
        self::assertSame(1, $site->version);
        self::assertSame('Hafenstraße 12', $fields->values('en', false)['address']);

        $this->assertInvalid(fn() => $this->runner()->run(new PublishSite()), '');
        $this->assertInvalid(fn() => $this->runner()->run(new DiscardSite()), '');
    }

    public function testKeepsValuesPerLanguage(): void
    {
        $this->runner()->run(new UpdateSite('de', ['brand' => 'Bohnenglück', 'address' => 'Hafenstraße 12']));

        $updated = $this->runner()->run(new UpdateSiteField('en', 'address', 'Harbour Street 12', 'Hafenstraße 12'));
        self::assertSame(['en', 'address', 'Harbour Street 12', 'Harbour Street 12'], [$updated->language, $updated->path, $updated->value, $updated->html]);
        self::assertNotNull($updated->entry);

        $site = $this->runner()->ask(new ShowSite());
        self::assertSame(['brand' => 'Bohnenglück', 'address' => 'Harbour Street 12'], $site->values['en']);
        self::assertSame('Hafenstraße 12', $site->values['de']['address']);

        // Without its own value a language shows the one of the first language again.
        $updated = $this->runner()->run(new UpdateSiteField('en', 'address', null, 'Harbour Street 12'));
        self::assertSame('Hafenstraße 12', $updated->value);
    }

    public function testChangesOneFieldOrSaysWhyNot(): void
    {
        $this->runner()->run(new UpdateSite('de', ['brand' => 'Bohnenglück', 'links' => [['text' => 'Eins'], ['text' => 'Zwei']]]));

        $updated = $this->runner()->run(new UpdateSiteField('de', 'links/1/text', 'Drei', 'Zwei'));
        self::assertSame('Drei', $updated->value);
        self::assertSame([['text' => 'Eins'], ['text' => 'Drei']], self::withoutIds($this->runner()->ask(new ShowSite())->values['de']['links']));

        // An entry also by its id (decision 0344).
        $links = $this->runner()->ask(new ShowSite())->values['de']['links'];
        self::assertIsArray($links);
        self::assertIsArray($links[0]);
        $first = $links[0]['id'] ?? null;
        self::assertIsString($first);
        self::assertSame('Erste', $this->runner()->run(new UpdateSiteField('de', 'links/' . $first . '/text', 'Erste', 'Eins'))->value);
        self::assertSame([['text' => 'Erste'], ['text' => 'Drei']], self::withoutIds($this->runner()->ask(new ShowSite())->values['de']['links']));

        try {
            $this->runner()->run(new UpdateSiteField('de', 'links/zzzzzzzz/text', 'Neu', null));
            self::fail('A missing entry was changed.');
        } catch (InvalidInputException $error) {
            self::assertArrayHasKey('/path', $error->errors);
        }

        try {
            $this->runner()->run(new UpdateSiteField('de', 'brand', 'Neu', 'Alt'));
            self::fail('A stale value was overwritten.');
        } catch (ConflictException) {
        }

        $this->assertInvalid(fn() => $this->runner()->run(new UpdateSiteField('de', 'phone', '040', null)), '/path');
        $this->assertInvalid(fn() => $this->runner()->run(new UpdateSiteField('de', 'brand/0/text', 'x', null)), '/path');
        $this->assertInvalid(fn() => $this->runner()->run(new UpdateSiteField('fr', 'brand', 'x', 'Bohnenglück')), '/language');
        $this->assertInvalid(fn() => $this->runner()->run(new UpdateSiteField('de', 'brand', str_repeat('x', 41), 'Bohnenglück')), '/value');
        $this->assertInvalid(fn() => $this->runner()->run(new UpdateSite('de', ['brand' => 7])), '/fields/brand');

        self::assertSame('Bohnenglück', $this->runner()->ask(new ShowSite())->values['de']['brand'], 'Nothing is saved when a value is wrong.');
    }

    public function testChecksRequiredFieldsOnlyWhenPublishing(): void
    {
        $site = $this->runner()->run(new UpdateSite('de', ['address' => 'Hafenstraße 12']));
        self::assertTrue($site->draft, 'A draft may lack the name.');

        $this->assertInvalid(fn() => $this->runner()->run(new PublishSite()), '/languages/de/brand');
    }

    public function testDropsADraftWithoutChangesAndDiscards(): void
    {
        $this->runner()->run(new UpdateSite('de', ['brand' => 'Bohnenglück']));
        $this->runner()->run(new PublishSite());

        $this->runner()->run(new UpdateSite('de', ['brand' => 'Kaffeeglück']));
        self::assertFalse($this->runner()->run(new UpdateSite('de', ['brand' => 'Bohnenglück']))->draft, 'The same values as published need no draft.');

        $this->runner()->run(new UpdateSite('de', ['brand' => 'Kaffeeglück']));
        $site = $this->runner()->run(new DiscardSite());
        self::assertFalse($site->draft);
        self::assertSame('Bohnenglück', $site->values['de']['brand']);
    }

    public function testRestoresAnEarlierVersion(): void
    {
        $this->runner()->run(new UpdateSite('de', ['brand' => 'Bohnenglück', 'address' => 'Hafenstraße 12']));
        $this->runner()->run(new PublishSite());
        $this->runner()->run(new UpdateSite('de', ['brand' => 'Kaffeeglück', 'address' => null]));
        $this->runner()->run(new PublishSite());

        $versions = $this->runner()->ask(new ListSiteVersions());
        self::assertSame([2, 1], array_map(static fn($version): int => $version->version, $versions), 'The newest first.');
        self::assertSame([true, false], array_map(static fn($version): bool => $version->current, $versions));

        // Decision 0348: an earlier version becomes a new draft; visitors keep the published one.
        $site = $this->runner()->run(new RestoreSite(1));
        self::assertTrue($site->draft);
        self::assertSame(['brand' => 'Bohnenglück', 'address' => 'Hafenstraße 12'], $site->values['de']);
        self::assertSame('Kaffeeglück', $this->get(SiteFields::class)->values('de', false)['brand']);

        $this->runner()->run(new UndoAction($this->lastEntry()->id));
        self::assertFalse($this->runner()->ask(new ShowSite())->draft, 'Restoring is undoable.');

        self::assertFalse($this->runner()->run(new RestoreSite(2))->draft, 'The published version needs no draft.');
        $this->assertInvalid(fn() => $this->runner()->run(new RestoreSite(9)), '/version');
    }

    public function testTellsTheOwnValuesOfALanguageAndTakesEmptyAsNone(): void
    {
        $this->runner()->run(new UpdateSite('de', ['brand' => 'Bohnenglück', 'address' => 'Hafenstraße 12']));
        $site = $this->runner()->run(new UpdateSite('en', ['brand' => 'Bean luck', 'address' => '']));

        self::assertSame(['brand' => 'Bean luck'], $site->own['en'], 'An empty value is none of its own (decision 0348).');
        self::assertSame('Hafenstraße 12', $site->values['en']['address']);

        $site = $this->runner()->run(new UpdateSite('en', ['brand' => '  ', 'logo' => [], 'address' => ['type' => 'doc', 'content' => [['type' => 'paragraph']]]]));
        self::assertSame([], $site->own['en']);
        self::assertSame('Bohnenglück', $site->values['en']['brand']);
        self::assertSame(['maxLength' => 40], array_values(array_filter($site->fields, static fn(FieldSummary $field): bool => 'brand' === $field->name))[0]->settings ?? null, 'Forms see the settings of a field.');
    }

    public function testShowsAndMarksTheFieldsOnEveryPage(): void
    {
        $start = $this->runner()->run(new CreatePage('acme/base/Page', ['de' => ['title' => 'Start', 'content' => ['fields' => ['heading' => 'Start']]]]))->id;
        $this->runner()->run(new PublishPages([$start]));
        $this->runner()->run(new UpdateSite('de', ['brand' => 'Bohnenglück', 'address' => 'Hafenstraße 12']));
        $this->runner()->run(new PublishSite());

        self::assertStringContainsString('<footer><p>Bohnenglück</p><p>Hafenstraße 12</p></footer>', $this->html('/'));
        self::assertSame('hit', $this->request('/')->getHeaderLine(PageCache::HEADER));

        // A draft keeps the cached page, the publication clears it.
        $this->runner()->run(new UpdateSiteField('de', 'brand', 'Kaffeeglück', 'Bohnenglück'));
        self::assertSame('hit', $this->request('/')->getHeaderLine(PageCache::HEADER));
        self::assertStringContainsString('<p data-fb-field="brand" data-fb-type="headline" data-fb-label="Name" data-fb-site tabindex="0" data-fb-value="&quot;Kaffeeglück&quot;">Kaffeeglück</p>', $this->html('/', $this->user(['admin'])));
        self::assertTrue($this->get(EditMode::class)->markingSite());

        // Who may change the page but not the website sees the fields unmarked.
        self::assertStringContainsString('<footer><p>Kaffeeglück</p>', $this->html('/', $this->user(['editor'], 'page:' . $start)));
        self::assertTrue($this->get(EditMode::class)->marking());
        self::assertFalse($this->get(EditMode::class)->markingSite());

        $this->runner()->run(new PublishSite());
        self::assertStringContainsString('<footer><p>Kaffeeglück</p>', $this->html('/'));

        $this->runner()->run(new UndoAction($this->lastEntry()->id));
        self::assertStringContainsString('<footer><p>Bohnenglück</p>', $this->html('/'), 'Undoing the publication brings the old values back.');
    }

    public function testMarksValuesFromTheFirstLanguage(): void
    {
        $start = $this->runner()->run(new CreatePage('acme/base/Page', [
            'de' => ['title' => 'Start', 'content' => ['fields' => ['heading' => 'Start']]],
            'en' => ['title' => 'Home', 'content' => ['fields' => ['heading' => 'Home']]],
        ]))->id;
        $this->runner()->run(new PublishPages([$start]));
        $this->runner()->run(new UpdateSite('de', ['brand' => 'Bohnenglück', 'address' => 'Hafenstraße 12']));
        $this->runner()->run(new PublishSite());

        self::assertStringContainsString('<footer><p>Bohnenglück</p><p>Hafenstraße 12</p></footer>', $this->html('/'));
        self::assertStringContainsString('<footer><p>Bohnenglück</p><p lang="de">Hafenstraße 12</p></footer>', $this->html('/en'), 'The German address on the English page says so, for screen readers and hyphens.');

        $this->runner()->run(new UpdateSite('en', ['address' => 'Harbour Street 12']));
        $this->runner()->run(new PublishSite());
        self::assertStringContainsString('<p>Harbour Street 12</p>', $this->html('/en'));
    }

    public function testEditorsMayChangeTheFields(): void
    {
        $editor = $this->context(['editor']);
        $this->runner()->run(new UpdateSite('de', ['brand' => 'Bohnenglück']), $editor);
        $this->runner()->run(new PublishSite(), $editor);

        $this->expectException(AccessDeniedException::class);
        $this->runner()->run(new UpdateSite('de', ['brand' => 'Anders']), $this->context([]));
    }

    /**
     * Live, so the page cache is in play (decision 0107).
     */
    protected function environment(): Environment
    {
        return Environment::Production;
    }

    protected function extensions(): Extensions
    {
        return FixtureSite::extensions('base', 'site');
    }

    protected function config(): array
    {
        return ['app' => ['url' => 'https://example.org'], 'theme' => 'acme/site', 'languages' => ['de' => ['title' => 'Deutsch'], 'en' => ['title' => 'English']]];
    }

    private function request(string $path, ?Actor $actor = null): ResponseInterface
    {
        // Each request starts afresh, as in its own process.
        $this->get(Urls::class)->clear();
        $this->get(PageOutline::class)->clear();
        $request = new ServerRequest('GET', 'https://example.org' . $path);

        if (null !== $actor) {
            $token = new AccessToken(1, (int) $actor->id, TokenType::Session, null, null, null, new DateTimeImmutable());
            $request = $request->withCookieParams([EditMode::COOKIE => '1'])->withAttribute(Authentication::class, new Authentication($actor, $token));
        }

        return $this->get(PageHandler::class)->handle($request);
    }

    private function html(string $path, ?Actor $actor = null): string
    {
        $response = $this->request($path, $actor);
        self::assertSame(200, $response->getStatusCode());

        return (string) $response->getBody();
    }

    /**
     * @param list<string> $roles
     */
    private function user(array $roles, ?string $scope = null): Actor
    {
        $user = $this->runner()->run(new CreateUser('site' . bin2hex(random_bytes(3)) . '@example.com', 'User', 'kaffeebohne42'))->user;

        foreach ($roles as $role) {
            $this->runner()->run(new AssignRole($user->id, $role, $scope));
        }

        return Actor::user($user->id, $user->name);
    }

    /**
     * @param list<string> $roles
     */
    private function context(array $roles): Context
    {
        return new Context($this->user($roles), Channel::Api);
    }

    private function assertInvalid(callable $callback, string $pointer): void
    {
        try {
            $callback();
        } catch (InvalidInputException $error) {
            self::assertArrayHasKey($pointer, $error->messages, implode(', ', array_keys($error->messages)));

            return;
        }

        self::fail('Expected invalid input at ' . $pointer);
    }
}
