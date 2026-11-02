<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Theme;

use Frootbox\Core\Extension\Extensions;
use Frootbox\Core\Field\Field;
use Frootbox\Core\Field\FieldTypes;
use Frootbox\Core\Field\Types\IconType;
use Frootbox\Core\Tests\Support\FixtureSite;
use Frootbox\Core\Tests\Support\NotesTestCase;
use Frootbox\Core\Theme\Icons;
use Frootbox\Core\Theme\IconSummary;
use Frootbox\Core\Theme\ListIcons;
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

        self::assertSame(['map-pin', 'star', 'truck'], $icons->names(), 'Files with other names do not count.');
        self::assertSame('acme/base', $icons->themeOf('map-pin'));
        self::assertSame('acme/site', $icons->themeOf('star'));
        self::assertStringContainsString('<circle cx="12" cy="12" r="9"/>', (string) $icons->svg('star'));
        self::assertNull($icons->svg('rocket'));
    }

    public function testHidesTheSymbolFromScreenReaders(): void
    {
        self::assertSame(
            '<svg aria-hidden="true" focusable="false" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><circle cx="12" cy="10" r="3"/></svg>',
            $this->get(Icons::class)->svg('map-pin'),
            'Without declaration and comment, with its own aria-hidden and role gone.',
        );
    }

    public function testTheFieldGivesTheSymbolAndChecksTheName(): void
    {
        $type = $this->get(FieldTypes::class)->get('icon');
        $field = new Field('icon', 'icon', 'Icon');

        $output = $type->output('truck', $field);
        self::assertInstanceOf(Markup::class, $output);
        self::assertStringStartsWith('<svg aria-hidden="true" focusable="false"', (string) $output);
        self::assertSame('', $type->output('rocket', $field), 'A symbol the theme no longer has gives nothing.');
        self::assertSame('', $type->output(null, $field));

        self::assertSame([], $type->validate('star', $field));
        self::assertSame(['' => 'names no symbol of the theme. Known are: map-pin, star, truck.'], $type->validate('rocket', $field));
        self::assertSame('^[a-z0-9]+(?:-[a-z0-9]+)*$', $type->valueSchema($field)['pattern']);
    }

    public function testListsTheSymbolsByLabel(): void
    {
        $list = $this->runner()->ask(new ListIcons());

        self::assertSame(['Map pin', 'Star', 'Truck'], array_map(static fn(IconSummary $icon): string => $icon->label, $list));
        self::assertSame('map-pin', $list[0]->name);
        self::assertStringStartsWith('<svg aria-hidden="true"', $list[0]->svg);
    }

    protected function extensions(): Extensions
    {
        return FixtureSite::extensions();
    }

    protected function config(): array
    {
        return ['theme' => 'acme/site'];
    }
}
