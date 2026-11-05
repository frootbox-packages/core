<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Media;

use Frootbox\Core\Action\ConflictException;
use Frootbox\Core\Extension\Extensions;
use Frootbox\Core\Media\CreateMedium;
use Frootbox\Core\Media\DeleteMedium;
use Frootbox\Core\Media\ListMedia;
use Frootbox\Core\Media\ListMediumUsages;
use Frootbox\Core\Media\MediaReferences;
use Frootbox\Core\Media\MediumDetails;
use Frootbox\Core\Media\MediumUsage;
use Frootbox\Core\Media\ObjectMedia;
use Frootbox\Core\Media\Uploads;
use Frootbox\Core\ProjectPaths;
use Frootbox\Core\Revision\Revisions;
use Frootbox\Core\Tests\Fixtures\Plugin\PublishItems;
use Frootbox\Core\Tests\Fixtures\Plugin\SaveItem;
use Frootbox\Core\Tests\Support\FixtureSite;
use Frootbox\Core\Tests\Support\NotesTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Media that objects of modules use count like those of pages (decision 0287).
 */
#[CoversClass(ObjectMedia::class)]
#[CoversClass(MediaReferences::class)]
#[CoversClass(Revisions::class)]
final class ObjectMediaTest extends NotesTestCase
{
    private const string ITEM = '01JOBJECTITEM0000000000001';

    public function testAMediumInAPublishedObjectCannotBeDeleted(): void
    {
        $photo = $this->medium();
        $unused = $this->medium();
        $this->runner()->run(new SaveItem(self::ITEM, ['de' => 'Neue Sorten'], ['de' => 'neue-sorten'], fields: ['gallery' => ['media' => $photo->id, 'alt' => 'Bohnen']]));

        self::assertSame([['acme/plugin/item', self::ITEM, '', '', true, false, 'Items']], $this->usages($photo->id), 'Only in the draft, without a published title yet.');
        self::assertSame([$unused->id], $this->unused());

        $this->runner()->run(new PublishItems([self::ITEM]));
        self::assertSame([['acme/plugin/item', self::ITEM, '', 'Neue Sorten', false, true, 'Items']], $this->usages($photo->id));

        try {
            $this->runner()->run(new DeleteMedium($photo->id));
            self::fail('The medium is live in the object.');
        } catch (ConflictException $exception) {
            self::assertStringContainsString('1 published place', $exception->getMessage());
        }

        $this->runner()->run(new SaveItem(self::ITEM, ['de' => 'Neue Sorten'], ['de' => 'neue-sorten'], fields: ['gallery' => null]));
        self::assertSame([['acme/plugin/item', self::ITEM, '', 'Neue Sorten', false, true, 'Items']], $this->usages($photo->id), 'Visitors still see it until the object is published.');

        $this->runner()->run(new PublishItems([self::ITEM]));
        self::assertSame([], $this->usages($photo->id));
        $this->runner()->run(new DeleteMedium($photo->id));
        self::assertCount(1, $this->unused());
    }

    public function testFindsMediaAtAnyDepthAndOnlyRealIds(): void
    {
        $id = '01JMEDXA000000000000000001';
        $other = '01JMEDXA000000000000000002';

        self::assertSame([$id, $other], MediaReferences::inValue([
            'image' => ['media' => $id, 'alt' => 'Bohnen'],
            'fields' => ['clips' => [['video' => ['media' => $id, 'poster' => $other]]], 'icon' => 'media:' . $id],
        ]));
        self::assertSame([], MediaReferences::inValue(['media' => 'nope', 'title' => $id, 'icon' => 'coffee']), 'Only ids under media or poster, and symbols with media:.');
    }

    public function testFindsRevisionsByTextWithoutWildcards(): void
    {
        $this->runner()->run(new SaveItem(self::ITEM, ['de' => '100% Arabica'], ['de' => 'arabica']));
        $revisions = $this->get(Revisions::class);

        self::assertCount(1, $revisions->current(['page'], '100% Arabica'));
        self::assertSame([], $revisions->current(['page'], '100_ Arabica'), 'An underscore is no wildcard.');
        self::assertSame([], $revisions->current(['acme/plugin/item'], 'Arabica'), 'Types can be left out.');
    }

    protected function extensions(): Extensions
    {
        return FixtureSite::extensions('base', 'site', 'plugin', 'objects');
    }

    protected function config(): array
    {
        return ['app' => ['name' => 'Testsite', 'url' => 'https://example.org'], 'theme' => 'acme/objects', 'languages' => ['de' => ['title' => 'Deutsch'], 'en' => ['title' => 'English', 'fallback' => 'de']]];
    }

    /**
     * @return list<array{string, string, string, string, bool, bool, string}>
     */
    private function usages(string $id): array
    {
        return array_map(
            static fn(MediumUsage $usage): array => [$usage->type, $usage->id, $usage->language, $usage->title, $usage->draft, $usage->published, $usage->kind],
            $this->runner()->ask(new ListMediumUsages($id)),
        );
    }

    /**
     * @return list<string>
     */
    private function unused(): array
    {
        return array_map(static fn(MediumDetails $medium): string => $medium->id, $this->runner()->ask(new ListMedia(unused: true))->items);
    }

    /**
     * A new image, each one different.
     */
    private function medium(): MediumDetails
    {
        $image = imagecreatetruecolor(320, 200);
        imagefilledrectangle($image, 0, 0, 160, 200, (int) imagecolorallocate($image, random_int(0, 255), random_int(0, 255), random_int(0, 255)));
        $file = $this->get(ProjectPaths::class)->var('object-media-' . bin2hex(random_bytes(4)) . '.png');
        @mkdir(\dirname($file), 0o775, true);
        imagepng($image, $file);

        return $this->runner()->run(new CreateMedium($this->get(Uploads::class)->store($file, 'bild.png')));
    }
}
