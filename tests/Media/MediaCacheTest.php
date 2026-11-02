<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Media;

use Frootbox\Core\Cache\ClearPageCache;
use Frootbox\Core\Cache\PageCache;
use Frootbox\Core\Config\Environment;
use Frootbox\Core\Extension\Extensions;
use Frootbox\Core\Media\CreateMedium;
use Frootbox\Core\Media\PublishMedia;
use Frootbox\Core\Media\UpdateMedium;
use Frootbox\Core\Media\Uploads;
use Frootbox\Core\Page\CreatePage;
use Frootbox\Core\Page\PageHandler;
use Frootbox\Core\Page\PublishPages;
use Frootbox\Core\Page\UpdatePage;
use Frootbox\Core\ProjectPaths;
use Frootbox\Core\Tests\Support\FixtureSite;
use Frootbox\Core\Tests\Support\NotesTestCase;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Drafts of pages and media change nothing for visitors, so the page
 * cache stays; publishing a medium clears it (decisions 0107, 0146, 0147).
 */
#[CoversClass(ClearPageCache::class)]
final class MediaCacheTest extends NotesTestCase
{
    public function testDraftsKeepThePageCacheAndPublishingClearsIt(): void
    {
        $file = $this->get(ProjectPaths::class)->var('cache-' . bin2hex(random_bytes(4)) . '.png');
        @mkdir(\dirname($file), 0o775, true);
        imagepng(imagecreatetruecolor(320, 200), $file);
        $medium = $this->runner()->run(new CreateMedium($this->get(Uploads::class)->store($file, 'bild.png')));
        $page = $this->runner()->run(new CreatePage('acme/base/Page', ['de' => ['title' => 'Start', 'content' => $this->content('Bilder', $medium->id)]]))->id;
        $this->runner()->run(new PublishPages([$page]));

        self::assertSame('miss', $this->cached());
        self::assertSame('hit', $this->cached());

        $this->runner()->run(new UpdatePage($page, ['de' => ['content' => $this->content('Neu', $medium->id)]]));
        self::assertSame('hit', $this->cached(), 'A page draft that uses media keeps the cache.');

        $this->runner()->run(new UpdateMedium($medium->id, texts: ['de' => ['alt' => 'Neu']]));
        self::assertSame('hit', $this->cached(), 'So does the draft of a medium.');

        $this->runner()->run(new PublishMedia([$medium->id]));
        self::assertSame('miss', $this->cached());
    }

    protected function environment(): Environment
    {
        return Environment::Production;
    }

    protected function extensions(): Extensions
    {
        return FixtureSite::extensions('base', 'site', 'media');
    }

    protected function config(): array
    {
        return ['app' => ['url' => 'https://example.org'], 'theme' => 'acme/site', 'languages' => ['de' => ['title' => 'Deutsch']]];
    }

    private function cached(): string
    {
        return $this->get(PageHandler::class)->handle(new ServerRequest('GET', 'https://example.org/'))->getHeaderLine(PageCache::HEADER);
    }

    /**
     * @return array<string, mixed>
     */
    private function content(string $heading, string $cover): array
    {
        return ['fields' => ['heading' => $heading], 'areas' => ['main' => [
            ['block' => 'acme/media/Gallery', 'fields' => ['cover' => ['media' => $cover]]],
        ]]];
    }
}
