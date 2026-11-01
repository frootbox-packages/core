<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Theme;

use Frootbox\Core\Extension\ExtensionPackage;
use Frootbox\Core\Extension\Extensions;
use Frootbox\Core\Tests\Support\FixtureSite;
use Frootbox\Core\Theme\Font;
use Frootbox\Core\Theme\Theme;
use Frootbox\Core\Theme\ThemeChain;
use Frootbox\Core\Theme\ThemeException;
use Frootbox\Core\Theme\TokenDefinition;
use Frootbox\Core\Theme\TokenType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Theme::class)]
#[CoversClass(ThemeChain::class)]
#[CoversClass(TokenDefinition::class)]
#[CoversClass(Font::class)]
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

        self::assertSame(['color-primary' => '#c00', 'color-text' => '#222', 'font-heading' => 'sans', 'radius' => '4px', 'space' => 'normal'], $chain->tokens());
        self::assertSame(':root{--color-primary:#cc0000;--color-text:#222;--font-heading:' . Font::system()['sans']->family . ';--radius:4px;--space:2rem;}', $chain->css());
    }

    public function testDescribesTheTokensEditorsMayChange(): void
    {
        $chain = ThemeChain::resolve(FixtureSite::extensions(), 'acme/site');
        $definitions = $chain->definitions();

        self::assertSame(['color-primary', 'font-heading', 'radius', 'space'], array_keys($definitions));
        // The child changes the value with the short form, the description stays.
        self::assertSame(TokenType::Color, $definitions['color-primary']->type);
        self::assertSame('Main colour', $definitions['color-primary']->label);
        self::assertSame('color-text', $definitions['color-primary']->contrast, 'The contrast names the token next to it.');
        self::assertSame('px', $definitions['radius']->unit);
        self::assertSame([0.0, 20.0], [$definitions['radius']->min, $definitions['radius']->max]);
        self::assertSame(['sans', 'serif', 'rounded', 'mono', 'lora'], array_keys($chain->fonts()));
    }

    public function testUsesTheValuesEditorsChoseIfTheyFit(): void
    {
        $chain = ThemeChain::resolve(FixtureSite::extensions(), 'acme/site');

        self::assertSame(
            ':root{--color-primary:#123456;--color-text:#222;--font-heading:"Lora", serif;--radius:12px;--space:4rem;}',
            $chain->css(['color-primary' => '#123456', 'font-heading' => 'lora', 'radius' => '12px', 'space' => 'wide', 'color-text' => '#000']),
            'A token without a type stays as the theme says.',
        );
        self::assertSame(
            ':root{--color-primary:#cc0000;--color-text:#222;--font-heading:' . Font::system()['sans']->family . ';--radius:4px;--space:2rem;}',
            $chain->css(['color-primary' => 'red', 'font-heading' => 'comic', 'radius' => '30px', 'space' => 'huge']),
            'Values that do not fit are left out.',
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function valueProblems(): iterable
    {
        yield 'colour' => ['color-primary', 'blue'];
        yield 'length without unit' => ['radius', '4'];
        yield 'length in another unit' => ['radius', '1rem'];
        yield 'length too large' => ['radius', '21px'];
        yield 'font' => ['font-heading', 'Arial'];
        yield 'choice' => ['space', '3rem'];
    }

    #[DataProvider('valueProblems')]
    public function testExplainsValuesThatDoNotFit(string $name, string $value): void
    {
        $chain = ThemeChain::resolve(FixtureSite::extensions(), 'acme/site');

        self::assertNotNull($chain->definitions()[$name]->problem($value, $chain->fonts()));
        self::assertNull($chain->definitions()[$name]->problem($chain->tokens()[$name], $chain->fonts()));
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
        yield 'no type' => [new Extensions([self::theme('acme/a', ['tokens' => ['x' => ['type' => 'size', 'value' => '1']]])]), 'acme/a', 'the token "x" needs a type: color, font, length, choice'];
        yield 'unknown description' => [new Extensions([self::theme('acme/a', ['tokens' => ['x' => ['type' => 'color', 'value' => '#fff', 'help' => 'x']]])]), 'acme/a', 'the token "x" contains unknown keys: help'];
        yield 'choice without options' => [new Extensions([self::theme('acme/a', ['tokens' => ['x' => ['type' => 'choice', 'value' => 'a']]])]), 'acme/a', 'is a choice and needs "options"'];
        yield 'value of the wrong type' => [new Extensions([self::theme('acme/a', ['tokens' => ['font' => ['body' => ['type' => 'font', 'value' => 'Arial']]]])]), 'acme/a', 'The token "font-body" of the theme acme/a is "Arial" but must be one of the fonts sans, serif'];
        yield 'contrast without colour' => [new Extensions([self::theme('acme/a', ['tokens' => ['color' => ['a' => ['type' => 'color', 'value' => '#fff', 'contrast' => 'b']]]])]), 'acme/a', 'is read against "b", which is no colour token'];
        yield 'bad font' => [new Extensions([self::theme('acme/a', ['fonts' => ['lora' => ['label' => 'Lora']]])]), 'acme/a', 'the font "lora" needs a lower case key, a "label" and a "family"'];
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
