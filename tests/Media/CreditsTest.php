<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Media;

use Frootbox\Core\Action\InvalidInputException;
use Frootbox\Core\Extension\Extensions;
use Frootbox\Core\Media\CreateMedium;
use Frootbox\Core\Media\Credit;
use Frootbox\Core\Media\CreditPage;
use Frootbox\Core\Media\Credits;
use Frootbox\Core\Media\ListCredits;
use Frootbox\Core\Media\MediumDetails;
use Frootbox\Core\Media\Uploads;
use Frootbox\Core\Page\CreatePage;
use Frootbox\Core\Page\PublishPages;
use Frootbox\Core\ProjectPaths;
use Frootbox\Core\Site\PublishSite;
use Frootbox\Core\Site\UpdateSite;
use Frootbox\Core\Tests\Support\FixtureSite;
use Frootbox\Core\Tests\Support\NotesTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(Credits::class)]
#[CoversClass(Credit::class)]
#[CoversClass(CreditPage::class)]
#[CoversClass(ListCredits::class)]
final class CreditsTest extends NotesTestCase
{
    public function testListsThePublishedMediaWithACopyrightThatVisitorsSee(): void
    {
        $photo = $this->medium('Zoe Muster');
        $logo = $this->medium('Agentur Beispiel');
        $draft = $this->medium('Nur im Entwurf');
        $nameless = $this->medium(null);

        $start = $this->page('Start', $photo->id);
        $this->page('Galerie', $nameless->id, $start);
        $this->runner()->run(new CreatePage('acme/base/Page', ['de' => ['title' => 'Neu', 'content' => $this->content($draft->id)]], $start));
        $this->runner()->run(new UpdateSite('de', ['brand' => 'Bohnenglück', 'logo' => ['media' => $logo->id]]));
        $this->runner()->run(new PublishSite());

        $credits = $this->runner()->ask(new ListCredits('de'));

        self::assertSame(
            [['Agentur Beispiel', true, []], ['Zoe Muster', false, [[$start, 'Start', '/']]]],
            array_map(static fn(Credit $credit): array => [
                $credit->copyright,
                $credit->everywhere,
                array_map(static fn(CreditPage $page): array => [$page->id, $page->title, $page->url], $credit->pages),
            ], $credits),
            'By copyright; without a copyright, or only in a draft, a medium is left out.',
        );
        self::assertStringStartsWith('/media/' . $photo->id . '/', (string) $credits[1]->preview);
        self::assertStringContainsString('-160x0-', (string) $credits[1]->preview);
        self::assertSame([160, 100], [$credits[1]->previewWidth, $credits[1]->previewHeight]);
        self::assertSame([], $this->runner()->ask(new ListCredits('en')), 'Nothing is published in English.');
    }

    public function testRejectsALanguageTheWebsiteDoesNotHave(): void
    {
        $this->expectException(InvalidInputException::class);

        $this->runner()->ask(new ListCredits('fr'));
    }

    protected function extensions(): Extensions
    {
        return FixtureSite::extensions('base', 'site', 'media');
    }

    protected function config(): array
    {
        return ['app' => ['url' => 'https://example.org'], 'theme' => 'acme/site', 'languages' => ['de' => ['title' => 'Deutsch'], 'en' => ['title' => 'English']]];
    }

    private function page(string $title, string $cover, ?string $parent = null): string
    {
        $id = $this->runner()->run(new CreatePage('acme/base/Page', ['de' => ['title' => $title, 'content' => $this->content($cover)]], $parent))->id;
        $this->runner()->run(new PublishPages([$id]));

        return $id;
    }

    /**
     * @return array<string, mixed>
     */
    private function content(string $cover): array
    {
        return ['fields' => ['heading' => 'Bilder'], 'areas' => ['main' => [
            ['block' => 'acme/media/Gallery', 'fields' => ['cover' => ['media' => $cover]]],
        ]]];
    }

    private function medium(?string $copyright): MediumDetails
    {
        $image = imagecreatetruecolor(320, 200);
        imagefilledrectangle($image, 0, 0, 160, 200, (int) imagecolorallocate($image, random_int(0, 255), random_int(0, 255), random_int(0, 255)));
        $file = $this->get(ProjectPaths::class)->var('credit-' . bin2hex(random_bytes(4)) . '.png');
        @mkdir(\dirname($file), 0o775, true);
        imagepng($image, $file);

        return $this->runner()->run(new CreateMedium($this->get(Uploads::class)->store($file, 'bild.png'), copyright: $copyright));
    }
}
