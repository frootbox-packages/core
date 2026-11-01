<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Media;

use Frootbox\Core\Action\Undo\UndoAction;
use Frootbox\Core\Extension\Extensions;
use Frootbox\Core\Media\CreateMedium;
use Frootbox\Core\Media\ListMedia;
use Frootbox\Core\Media\ListMediumUsages;
use Frootbox\Core\Media\MediaReferences;
use Frootbox\Core\Media\MediaUsage;
use Frootbox\Core\Media\MediaUsages;
use Frootbox\Core\Media\MediaUsagesCommand;
use Frootbox\Core\Media\MediumDetails;
use Frootbox\Core\Media\MediumUsage;
use Frootbox\Core\Media\RebuildMediaUsages;
use Frootbox\Core\Media\ShowMedium;
use Frootbox\Core\Media\Uploads;
use Frootbox\Core\Media\UsageIndex;
use Frootbox\Core\Page\CreatePage;
use Frootbox\Core\Page\DeletePage;
use Frootbox\Core\Page\DiscardPage;
use Frootbox\Core\Page\PublishPages;
use Frootbox\Core\Page\UpdatePage;
use Frootbox\Core\ProjectPaths;
use Frootbox\Core\Site\PublishSite;
use Frootbox\Core\Site\UpdateSite;
use Frootbox\Core\Tests\Support\FixtureSite;
use Frootbox\Core\Tests\Support\NotesTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(UsageIndex::class)]
#[CoversClass(MediaUsages::class)]
#[CoversClass(MediaUsage::class)]
#[CoversClass(MediumUsage::class)]
#[CoversClass(ListMediumUsages::class)]
#[CoversClass(RebuildMediaUsages::class)]
#[CoversClass(MediaUsagesCommand::class)]
#[CoversClass(MediaReferences::class)]
final class MediaUsagesTest extends NotesTestCase
{
    public function testKnowsWhereAPageUsesAMediumInDraftAndPublished(): void
    {
        $first = $this->medium();
        $second = $this->medium();
        $unused = $this->medium();
        $page = $this->runner()->run(new CreatePage('acme/base/Page', [
            'de' => ['title' => 'Start', 'content' => $this->content($first->id)],
            'en' => ['title' => 'Home', 'content' => $this->content(null)],
        ]))->id;

        self::assertSame([['page', $page, 'de', 'Start', true, false]], $this->usages($first->id), 'Only the German draft uses it.');

        $this->runner()->run(new PublishPages([$page]));
        self::assertSame([['page', $page, 'de', 'Start', false, true]], $this->usages($first->id));

        $this->runner()->run(new UpdatePage($page, ['de' => ['content' => $this->content($second->id)]]));
        self::assertSame([['page', $page, 'de', 'Start', false, true]], $this->usages($first->id), 'Visitors still see the first one.');
        self::assertSame([['page', $page, 'de', 'Start', true, false]], $this->usages($second->id));

        $found = $this->runner()->ask(new ListMedia(unused: true));
        self::assertSame([$unused->id], array_map(static fn(MediumDetails $medium): string => $medium->id, $found->items));
        self::assertSame(1, $found->total);

        $this->runner()->run(new DiscardPage($page));
        self::assertSame([], $this->usages($second->id));

        $this->runner()->run(new UndoAction($this->lastEntry()->id));
        self::assertSame([['page', $page, 'de', 'Start', true, false]], $this->usages($second->id), 'Undo brings the index back with the draft.');

        $this->runner()->run(new DeletePage($page));
        self::assertSame([], $this->usages($first->id));
        self::assertSame([], $this->usages($second->id));
        self::assertCount(3, $this->runner()->ask(new ListMedia(unused: true))->items);
    }

    public function testKnowsWhereTheFieldsOfTheWebsiteUseAMediumAndMakeItPublic(): void
    {
        $logo = $this->medium();

        $this->runner()->run(new UpdateSite('de', ['brand' => 'Bohnenglück', 'logo' => ['media' => $logo->id]]));
        self::assertSame([['site', 'acme/site', 'de', '', true, false]], $this->usages($logo->id));
        self::assertFalse($this->runner()->ask(new ShowMedium($logo->id))->public);

        $this->runner()->run(new PublishSite());
        self::assertSame([['site', 'acme/site', 'de', '', false, true]], $this->usages($logo->id));
        self::assertTrue($this->runner()->ask(new ShowMedium($logo->id))->public, 'Like a page, the published fields make their media public.');
    }

    public function testBuildsTheIndexAnew(): void
    {
        $medium = $this->medium();
        $page = $this->runner()->run(new CreatePage('acme/base/Page', ['de' => ['title' => 'Start', 'content' => $this->content($medium->id)]]))->id;
        $this->runner()->run(new PublishPages([$page]));
        $this->connection()->executeStatement('DELETE FROM media_usages');
        $this->connection()->executeStatement("INSERT INTO media_usages (media_id, owner_type, owner_id, language, published) VALUES ('01J00000000000000000000000', 'page', '01J00000000000000000000001', 'de', 1)");

        self::assertSame(2, $this->runner()->run(new RebuildMediaUsages()), 'One entry is back, one left over is gone.');
        self::assertSame([['page', $page, 'de', 'Start', false, true]], $this->usages($medium->id));
        self::assertSame(1, $this->get(MediaUsages::class)->count());

        $tester = new CommandTester($this->get(MediaUsagesCommand::class));
        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('0 entry/entries changed', $tester->getDisplay());
    }

    protected function extensions(): Extensions
    {
        return FixtureSite::extensions('base', 'site', 'media');
    }

    protected function config(): array
    {
        return ['app' => ['url' => 'https://example.org'], 'theme' => 'acme/site', 'languages' => ['de' => ['title' => 'Deutsch'], 'en' => ['title' => 'English']]];
    }

    /**
     * @return list<array{string, string, string, string, bool, bool}>
     */
    private function usages(string $id): array
    {
        return array_map(
            static fn(MediumUsage $usage): array => [$usage->type, $usage->id, $usage->language, $usage->title, $usage->draft, $usage->published],
            $this->runner()->ask(new ListMediumUsages($id)),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function content(?string $cover): array
    {
        return ['fields' => ['heading' => 'Bilder'], 'areas' => ['main' => [
            ['block' => 'acme/media/Gallery', 'fields' => null === $cover ? [] : ['cover' => ['media' => $cover]]],
        ]]];
    }

    /**
     * A new image, each one different.
     */
    private function medium(): MediumDetails
    {
        $image = imagecreatetruecolor(320, 200);
        imagefilledrectangle($image, 0, 0, 160, 200, (int) imagecolorallocate($image, random_int(0, 255), random_int(0, 255), random_int(0, 255)));
        $file = $this->get(ProjectPaths::class)->var('usage-' . bin2hex(random_bytes(4)) . '.png');
        @mkdir(\dirname($file), 0o775, true);
        imagepng($image, $file);

        return $this->runner()->run(new CreateMedium($this->get(Uploads::class)->store($file, 'bild.png')));
    }
}
