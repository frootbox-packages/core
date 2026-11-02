<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Theme;

use Frootbox\Core\Action\CurrentContext;
use Frootbox\Core\Extension\Extensions;
use Frootbox\Core\Field\Field;
use Frootbox\Core\Field\FieldTypes;
use Frootbox\Core\Field\ReferencesMedia;
use Frootbox\Core\Field\Types\IconType;
use Frootbox\Core\Media\CreateMedium;
use Frootbox\Core\Media\MediumDetails;
use Frootbox\Core\Media\UploadReceiver;
use Frootbox\Core\ProjectPaths;
use Frootbox\Core\Tests\Support\FixtureSite;
use Frootbox\Core\Tests\Support\NotesTestCase;
use Frootbox\Core\Theme\Icons;
use Frootbox\Core\Theme\IconSetSummary;
use Frootbox\Core\Theme\IconSummary;
use Frootbox\Core\Theme\ListIcons;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\UploadedFile;
use PHPUnit\Framework\Attributes\CoversClass;
use Twig\Markup;

#[CoversClass(Icons::class)]
#[CoversClass(IconType::class)]
#[CoversClass(ListIcons::class)]
final class IconsTest extends NotesTestCase
{
    public function testFindsTheSymbolsOfTheThemesAndTheChildWins(): void
    {
        $icons = $this->get(Icons::class);

        self::assertSame(['map-pin', 'star', 'truck'], $icons->names(Icons::THEME), 'Files with other names do not count.');
        self::assertSame('acme/base', $icons->packageOf('map-pin'));
        self::assertSame('acme/site', $icons->packageOf('star'));
        self::assertStringContainsString('<circle cx="12" cy="12" r="9"/>', (string) $icons->svg('star'));
        self::assertNull($icons->svg('rocket'));
        self::assertFalse($icons->has('rocket'));
    }

    public function testHidesTheSymbolFromScreenReadersAndGivesItTheColorOfTheText(): void
    {
        self::assertSame(
            '<svg aria-hidden="true" focusable="false" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="10" r="3"/></svg>',
            $this->get(Icons::class)->svg('map-pin'),
            'Without declaration and comment, with its own aria-hidden and role gone.',
        );
    }

    public function testFindsTheSetsOfExtensions(): void
    {
        $icons = $this->get(Icons::class);

        self::assertSame(['theme', 'acme/news/solid', 'media'], array_keys($icons->sets()), 'Folders and files with other names do not count.');
        self::assertSame(['map-pin', 'star', 'truck', 'acme/news/solid/bell', 'acme/news/solid/rocket'], $icons->names());
        self::assertSame(['acme/news/solid/bell', 'acme/news/solid/rocket'], $icons->names('acme/news/solid'));
        self::assertSame('acme/news/solid', $icons->setOf('acme/news/solid/bell'));
        self::assertSame('acme/news', $icons->packageOf('acme/news/solid/bell'));
        self::assertTrue($icons->has('acme/news/solid/bell'));
        self::assertFalse($icons->has('acme/news/solid/star'));
        self::assertFalse($icons->has('acme/news/stray'));
        self::assertNull($icons->setOf('Acme/News/solid/bell'));

        $first = (string) $icons->svg('acme/news/solid/rocket');
        $second = (string) $icons->svg('acme/news/solid/rocket');
        self::assertStringNotContainsString('alert', $first, 'Cleaned like any SVG.');
        self::assertMatchesRegularExpression('/data-fb-svg="(fbi\d+)".*<style>\[data-fb-svg="\1"\] \.\1-flame\{fill:orange\}<\/style>.*<clipPath id="\1-body">.*class="\1-flame" clip-path="url\(#\1-body\)"/s', $first);
        self::assertNotSame($first, $second, 'Each output has ids of its own.');
    }

    public function testTakesSvgGraphicsOfTheMediaLibrary(): void
    {
        $icons = $this->get(Icons::class);
        $logo = $this->upload('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><circle cx="5" cy="5" r="5"/></svg>', 'Röstung.svg');
        $photo = $this->upload('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10" fill="#1d4ed8"><rect width="10" height="10"/></svg>', 'Band.svg');
        $name = 'media:' . $logo->id;

        self::assertSame(['media:' . $photo->id, $name], $icons->names(Icons::MEDIA), 'By file name.');
        self::assertSame(Icons::MEDIA, $icons->setOf($name));
        self::assertSame($logo->id, Icons::mediumId($name));
        self::assertSame('Röstung', $icons->labelOf($name));
        self::assertTrue($icons->has($name));
        self::assertNull($icons->svg($name), 'Visitors see a graphic only once it is published.');
        self::assertStringContainsString('<circle cx="5" cy="5" r="5"/>', (string) $icons->svg($name, true));

        self::assertStringContainsString('fill="#1d4ed8"', (string) $icons->svg('media:' . $photo->id, true), 'A graphic keeps its own colors.');

        $type = $this->get(FieldTypes::class)->get('icon');
        self::assertInstanceOf(ReferencesMedia::class, $type);
        self::assertSame([$logo->id], $type->media($name, new Field('icon', 'icon', 'Icon')));
        self::assertSame([], $type->media('truck', new Field('icon', 'icon', 'Icon')));
        self::assertSame([], $type->validate('media:01J0000000000000000000000Z', new Field('icon', 'icon', 'Icon')), 'A graphic deleted later does not keep the page from being saved.');
    }

    public function testTheFieldGivesTheSymbolAndChecksTheName(): void
    {
        $type = $this->get(FieldTypes::class)->get('icon');
        $field = new Field('icon', 'icon', 'Icon');

        $output = $type->output('truck', $field);
        self::assertInstanceOf(Markup::class, $output);
        self::assertStringStartsWith('<svg aria-hidden="true" focusable="false"', (string) $output);
        $bell = $type->output('acme/news/solid/bell', $field);
        self::assertInstanceOf(Markup::class, $bell);
        self::assertStringContainsString('<path d="M256 32', (string) $bell);
        self::assertSame('', $type->output('rocket', $field), 'A symbol that is gone gives nothing.');
        self::assertSame('', $type->output(null, $field));

        self::assertSame([], $type->validate('star', $field));
        self::assertSame([], $type->validate('acme/news/solid/bell', $field));
        self::assertSame(['' => 'names no symbol that is installed. icon.list tells the available ones.'], $type->validate('rocket', $field));

        self::assertSame(Icons::PATTERN, $type->valueSchema($field)['pattern']);
        $pattern = '/' . Icons::PATTERN . '/';
        foreach (['truck', 'acme/fontawesome/solid/truck', 'my-vendor/icons.pro/brand-x/a1', 'media:01J0000000000000000000000Z'] as $name) {
            self::assertMatchesRegularExpression($pattern, $name);
        }
        foreach (['Truck', 'acme/solid/truck', 'acme/fa/solid/', 'media:01j0000000000000000000000z', '../truck', 'acme/fa/solid/truck.svg'] as $name) {
            self::assertDoesNotMatchRegularExpression($pattern, $name);
        }
    }

    public function testListsTheSymbolsBySetAndLabel(): void
    {
        $list = $this->runner()->ask(new ListIcons());

        self::assertSame(['Map pin', 'Star', 'Truck', 'Bell', 'Rocket'], array_map(static fn(IconSummary $icon): string => $icon->label, $list->items));
        self::assertSame(5, $list->total);
        self::assertSame('map-pin', $list->items[0]->name);
        self::assertSame('theme', $list->items[0]->set);
        self::assertStringStartsWith('<svg aria-hidden="true"', $list->items[0]->svg);
        self::assertSame(
            [['theme', 'Theme', 3], ['acme/news/solid', 'News: Solid', 2], ['media', 'Own symbols', 0]],
            array_map(static fn(IconSetSummary $set): array => [$set->name, $set->label, $set->count], $list->sets),
        );
    }

    public function testFiltersAndPagesTheSymbols(): void
    {
        $names = fn(ListIcons $query): array => array_map(static fn(IconSummary $icon): string => $icon->name, $this->runner()->ask($query)->items);

        self::assertSame(['acme/news/solid/bell', 'acme/news/solid/rocket'], $names(new ListIcons(set: 'acme/news/solid')));
        self::assertSame(['map-pin'], $names(new ListIcons(search: 'PIN')));
        self::assertSame(['acme/news/solid/bell'], $names(new ListIcons(search: 'news/solid/b')));
        self::assertSame(['truck'], $names(new ListIcons(name: 'truck')));
        self::assertSame([], $names(new ListIcons(name: 'rocket')));
        self::assertSame(['truck', 'acme/news/solid/bell'], $names(new ListIcons(page: 2, perPage: 2)));

        $page = $this->runner()->ask(new ListIcons(page: 3, perPage: 2));
        self::assertSame([5, 3, 2], [$page->total, $page->page, $page->perPage]);
    }

    protected function extensions(): Extensions
    {
        return FixtureSite::extensions();
    }

    protected function config(): array
    {
        return ['theme' => 'acme/site'];
    }

    private function upload(string $bytes, string $name): MediumDetails
    {
        $file = $this->get(ProjectPaths::class)->var('icon-' . bin2hex(random_bytes(4)) . '.svg');
        @mkdir(\dirname($file), 0o775, true);
        file_put_contents($file, $bytes);
        $request = new Psr17Factory()->createServerRequest('POST', 'http://localhost/')
            ->withUploadedFiles([UploadReceiver::FIELD => new UploadedFile($file, \strlen($bytes), \UPLOAD_ERR_OK, $name)]);
        $upload = $this->get(UploadReceiver::class)->receive($request, $this->get(CurrentContext::class)->get()->actor);

        return $this->runner()->run(new CreateMedium($upload['upload']));
    }
}
