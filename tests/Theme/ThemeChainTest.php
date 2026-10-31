<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Theme;

use Frootbox\Core\Extension\ExtensionPackage;
use Frootbox\Core\Extension\Extensions;
use Frootbox\Core\Tests\Support\FixtureSite;
use Frootbox\Core\Theme\Theme;
use Frootbox\Core\Theme\ThemeChain;
use Frootbox\Core\Theme\ThemeException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Theme::class)]
#[CoversClass(ThemeChain::class)]
final class ThemeChainTest extends TestCase
{
    public function testFollowsTheParents(): void
    {
        $chain = ThemeChain::resolve(FixtureSite::extensions(), 'acme/site');

        self::assertSame(['acme/site', 'acme/base'], array_map(static fn(Theme $theme): string => $theme->name, $chain->themes));
        self::assertSame('acme/site', $chain->active()?->name);
        self::assertTrue($chain->contains('acme/base'));
        self::assertFalse($chain->contains('acme/other'));
        self::assertFalse($chain->contains('acme/news'));
    }

    public function testChildrenOverrideTokens(): void
    {
        $chain = ThemeChain::resolve(FixtureSite::extensions(), 'acme/site');

        self::assertSame(['color-primary' => '#c00', 'color-text' => '#222', 'radius' => '4px'], $chain->tokens());
        self::assertSame(':root{--color-primary:#c00;--color-text:#222;--radius:4px;}', $chain->css());
    }

    public function testWorksWithoutTheme(): void
    {
        $chain = ThemeChain::resolve(FixtureSite::extensions(), null);

        self::assertNull($chain->active());
        self::assertSame('', $chain->css());
    }

    /**
     * @return iterable<string, array{Extensions, string, string}>
     */
    public static function brokenChains(): iterable
    {
        yield 'not installed' => [FixtureSite::extensions(), 'acme/nope', 'The theme acme/nope from config/app.yaml is not installed.'];
        yield 'not a theme' => [FixtureSite::extensions(), 'acme/news', 'acme/news is not a theme'];
        yield 'parent missing' => [FixtureSite::extensions('site'), 'acme/site', 'The theme acme/site needs its parent acme/base, which is not installed.'];
        yield 'circle' => [
            new Extensions([self::theme('acme/a', ['parent' => 'acme/b']), self::theme('acme/b', ['parent' => 'acme/a'])]),
            'acme/a',
            'acme/a → acme/b → acme/a inherit from each other in a circle',
        ];
        yield 'unknown key' => [new Extensions([self::theme('acme/a', ['colors' => []])]), 'acme/a', 'contains unknown keys: colors'];
        yield 'bad token name' => [new Extensions([self::theme('acme/a', ['tokens' => ['Primary' => 'red']])]), 'acme/a', 'the token "Primary" must be lower case'];
        yield 'unsafe value' => [new Extensions([self::theme('acme/a', ['tokens' => ['color' => ['x' => 'red;} body {display:none']]])]), 'acme/a', 'the token "color-x" must not be empty or contain'];
        yield 'list value' => [new Extensions([self::theme('acme/a', ['tokens' => ['x' => true]])]), 'acme/a', 'the token "x" must be text or a number'];
        yield 'bad parent' => [new Extensions([self::theme('acme/a', ['parent' => 5])]), 'acme/a', '"parent" must be the package name of a theme'];
    }

    #[DataProvider('brokenChains')]
    public function testExplainsBrokenChains(Extensions $extensions, string $active, string $message): void
    {
        $this->expectException(ThemeException::class);
        $this->expectExceptionMessage($message);

        ThemeChain::resolve($extensions, $active);
    }

    /**
     * @param array<mixed> $theme
     */
    private static function theme(string $name, array $theme): ExtensionPackage
    {
        return new ExtensionPackage($name, '/tmp/' . $name, $name, theme: $theme);
    }
}
