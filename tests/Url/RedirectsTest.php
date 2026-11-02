<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Url;

use Frootbox\Core\Action\InvalidInputException;
use Frootbox\Core\Extension\Extensions;
use Frootbox\Core\Http\Exception\HttpException;
use Frootbox\Core\Page\CreatePage;
use Frootbox\Core\Page\PageHandler;
use Frootbox\Core\Page\PublishPages;
use Frootbox\Core\Tests\Support\FixtureSite;
use Frootbox\Core\Tests\Support\NotesTestCase;
use Frootbox\Core\Url\ClearNotFound;
use Frootbox\Core\Url\CreateRedirect;
use Frootbox\Core\Url\DeleteRedirect;
use Frootbox\Core\Url\ListNotFound;
use Frootbox\Core\Url\ListRedirects;
use Frootbox\Core\Url\NotFoundEntry;
use Frootbox\Core\Url\NotFoundLog;
use Frootbox\Core\Url\Redirect;
use Frootbox\Core\Url\RedirectEditor;
use Frootbox\Core\Url\Redirects;
use Frootbox\Core\Url\UpdateRedirect;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Http\Message\ResponseInterface;

#[CoversClass(Redirect::class)]
#[CoversClass(Redirects::class)]
#[CoversClass(RedirectEditor::class)]
#[CoversClass(CreateRedirect::class)]
#[CoversClass(UpdateRedirect::class)]
#[CoversClass(DeleteRedirect::class)]
#[CoversClass(ListRedirects::class)]
#[CoversClass(NotFoundLog::class)]
#[CoversClass(NotFoundEntry::class)]
#[CoversClass(ListNotFound::class)]
#[CoversClass(ClearNotFound::class)]
final class RedirectsTest extends NotesTestCase
{
    private string $about;

    protected function setUp(): void
    {
        parent::setUp();

        $page = static fn(string $title): array => ['title' => $title, 'content' => ['fields' => ['heading' => $title]]];
        $start = $this->runner()->run(new CreatePage('acme/base/Page', ['de' => $page('Start'), 'en' => $page('Home')]))->id;
        $this->about = $this->runner()->run(new CreatePage('acme/base/Page', ['de' => $page('Über uns'), 'en' => $page('About us')], $start))->id;
        $this->runner()->run(new PublishPages([$start, $this->about]));
    }

    public function testRedirectsToPagesAndUrls(): void
    {
        $toPage = $this->runner()->run(new CreateRedirect('/ueber-uns.html', 'page:' . $this->about));
        $this->runner()->run(new CreateRedirect('/shop', location: 'https://shop.example.com/', permanent: false));

        $this->assertRedirect('/ueber-uns?ref=alt', '/ueber-uns.html?ref=alt');
        $this->assertRedirect('https://shop.example.com/', '/shop', 302);
        $this->runner()->run(new CreateRedirect('/en/alt', 'page:' . $this->about));
        $this->assertRedirect('/en/about-us', '/en/alt', message: 'Inside a language the page is found in it.');

        // An earlier redirect that led to a new source takes over its destination.
        $this->runner()->run(new CreateRedirect('/a', location: '/b'));
        $this->runner()->run(new CreateRedirect('/b', location: '/c'));
        $this->assertRedirect('/c', '/a');

        $this->runner()->run(new UpdateRedirect($toPage->id, '/about.html', 'page:' . $this->about));
        $this->assertStatus(404, '/ueber-uns.html');
        $this->assertRedirect('/ueber-uns', '/about.html');

        self::assertSame(['/a', '/about.html', '/b', '/en/alt', '/shop'], array_map(static fn(Redirect $redirect): string => $redirect->path, $this->runner()->ask(new ListRedirects())));
        self::assertTrue($this->runner()->run(new DeleteRedirect($toPage->id)));
        $this->assertStatus(404, '/about.html');
    }

    public function testRefusesChainsLoopsAndHiddenPages(): void
    {
        $this->runner()->run(new CreateRedirect('/a', location: '/b'));

        $cases = [
            'loop' => [new CreateRedirect('/b', location: '/a'), ['/location' => 'is redirected itself (#1). Point to its destination /b directly.']],
            'itself' => [new CreateRedirect('/x', location: '/x?y=1'), ['/location' => 'must not be the path itself.']],
            'page' => [new CreateRedirect('/en/about-us', location: '/x'), ['/path' => \sprintf('is the address of page:%s. A page always wins over a redirect.', $this->about)]],
            'start' => [new CreateRedirect('/en', location: '/x'), ['/path' => 'is the address of page:' . $this->startId() . '. A page always wins over a redirect.']],
            'twice' => [new CreateRedirect('/a', location: '/x'), ['/path' => 'already has a redirect (#1).']],
            'both' => [new CreateRedirect('/x', 'page:' . $this->about, '/y'), ['/' => 'needs either "target" (e.g. "page:01J…") or "location" (a URL).']],
            'no page' => [new CreateRedirect('/x', 'page:01ARZ3NDEKTSV4RRFFQ69G5FAV'), ['/target' => 'There is no page 01ARZ3NDEKTSV4RRFFQ69G5FAV.']],
            'bad path' => [new CreateRedirect('alt/', location: '/x'), ['/path' => 'must be a path like "/old-page", without query, without slash at the end, at most 500 characters.']],
            'bad url' => [new CreateRedirect('/x', location: 'javascript:alert(1)'), ['/location' => 'must be a path like "/new-page" or a URL with http or https, at most 2000 characters.']],
        ];

        foreach ($cases as $name => [$action, $errors]) {
            try {
                $this->runner()->run($action);
                self::fail(\sprintf('"%s" was accepted.', $name));
            } catch (InvalidInputException $error) {
                self::assertSame($errors, $error->errors, $name);
            }
        }
    }

    public function testCountsWhatFoundNothing(): void
    {
        $this->assertStatus(404, '/gibts-nicht');
        $this->assertStatus(404, '/gibts-nicht?x=1');
        $this->assertStatus(404, '/alt');
        $this->assertStatus(404, '/alt', 'HEAD');
        $this->assertStatus(404, '/assets/acme/fehlt.css');

        $entries = $this->runner()->ask(new ListNotFound());
        self::assertSame(['/gibts-nicht' => 2, '/alt' => 1], array_column(array_map(static fn(NotFoundEntry $entry): array => [$entry->path, $entry->hits], $entries), 1, 0));

        $this->runner()->run(new CreateRedirect('/alt', 'page:' . $this->about));
        self::assertSame(['/gibts-nicht'], array_map(static fn(NotFoundEntry $entry): string => $entry->path, $this->runner()->ask(new ListNotFound())), 'A redirect takes its path out of the log.');

        self::assertSame(1, $this->runner()->run(new ClearNotFound()));
        self::assertSame([], $this->runner()->ask(new ListNotFound()));
    }

    public function testKeepsTheLogSmall(): void
    {
        $log = $this->get(NotFoundLog::class);
        $this->connection()->insert(NotFoundLog::TABLE, ['path' => '/oft', 'hits' => 50, 'first_seen_at' => '2026-01-01 00:00:00', 'last_seen_at' => '2026-01-01 00:00:00']);

        for ($i = 1; $i < NotFoundLog::MAX; ++$i) {
            $this->connection()->insert(NotFoundLog::TABLE, ['path' => '/selten-' . $i, 'hits' => 1, 'first_seen_at' => '2026-01-02 00:00:00', 'last_seen_at' => '2026-01-02 00:00:00']);
        }

        $log->record('/neu');
        $paths = array_map(static fn(NotFoundEntry $entry): string => $entry->path, $log->entries(NotFoundLog::MAX));

        self::assertCount(NotFoundLog::MAX, $paths);
        self::assertContains('/oft', $paths);
        self::assertContains('/neu', $paths);
        self::assertNotContains('/selten-1', $paths, 'The rarest and oldest goes.');

        $log->record(str_repeat('x', NotFoundLog::MAX_PATH_LENGTH + 1));
        self::assertCount(NotFoundLog::MAX, $log->entries(NotFoundLog::MAX));
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

    private function startId(): string
    {
        $id = $this->connection()->fetchOne('SELECT id FROM pages WHERE parent_id IS NULL');
        self::assertIsString($id);

        return $id;
    }

    private function request(string $path, string $method = 'GET'): ResponseInterface
    {
        return $this->get(PageHandler::class)->handle(new ServerRequest($method, 'https://example.org' . $path));
    }

    private function assertRedirect(string $location, string $path, int $status = 301, string $message = ''): void
    {
        $response = $this->request($path);

        self::assertSame($status, $response->getStatusCode(), $path . ' ' . $message);
        self::assertSame($location, $response->getHeaderLine('Location'), $path . ' ' . $message);
    }

    private function assertStatus(int $status, string $path, string $method = 'GET'): void
    {
        try {
            $this->request($path, $method);
            self::fail(\sprintf('%s was answered.', $path));
        } catch (HttpException $error) {
            self::assertSame($status, $error->status, $path);
        }
    }
}
