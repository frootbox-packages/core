<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Language;

use Frootbox\Core\Config\Config;
use Frootbox\Core\Config\ConfigException;
use Frootbox\Core\Config\Environment;
use Frootbox\Core\Language\Language;
use Frootbox\Core\Language\Languages;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Languages::class)]
#[CoversClass(Language::class)]
final class LanguagesTest extends TestCase
{
    public function testReadsTheConfig(): void
    {
        $languages = self::languages(['de' => ['title' => 'Deutsch'], 'de-ch' => ['title' => 'Schweizerdeutsch']]);

        self::assertSame('de', $languages->default()->code);
        self::assertSame(['de', 'de-ch'], $languages->codes());
        self::assertSame('Schweizerdeutsch', $languages->get('de-ch')->title);
        self::assertTrue($languages->has('de'));
        self::assertFalse($languages->has('en'));
    }

    public function testDefaultsToEnglish(): void
    {
        $languages = Languages::fromConfig(new Config(Environment::Development));

        self::assertSame(['en'], $languages->codes());
        self::assertSame('English', $languages->default()->title);
    }

    public function testExplainsMistakes(): void
    {
        $cases = [
            'list them by code' => [['de', 'en'], 'must list the languages by code'],
            'empty' => [[], 'must list the languages by code'],
            'no title' => [['de' => []], 'The language "de" in config/app.yaml needs a title'],
            'bad code' => [['DE' => ['title' => 'Deutsch']], '"DE" in "languages" is not a language code'],
        ];

        foreach ($cases as $name => [$configured, $message]) {
            try {
                self::languages($configured);
                self::fail(\sprintf('"%s" was accepted.', $name));
            } catch (ConfigException $error) {
                self::assertStringContainsString($message, $error->getMessage(), $name);
            }
        }

        $this->expectExceptionMessage('There is no language "fr". Languages: de.');
        self::languages(['de' => ['title' => 'Deutsch']])->get('fr');
    }

    public function testPlacesLanguagesInUrls(): void
    {
        $languages = self::languages([
            'de' => ['title' => 'Deutsch'],
            'en' => ['title' => 'English'],
            'fr' => ['title' => 'Français', 'fallback' => false],
            'it' => ['title' => 'Italiano', 'fallback' => 'fr'],
            'nl' => ['title' => 'Nederlands', 'domain' => 'Example.NL'],
        ]);

        self::assertSame(['', 'en', 'fr', 'it', ''], array_map(static fn(Language $language): string => $language->prefix, $languages->all()));
        self::assertSame('example.nl', $languages->get('nl')->domain);
        self::assertSame(['en', 'fr', 'it'], $languages->prefixes());
        self::assertSame(['en', 'de'], $languages->contentLanguages('en'));
        self::assertSame(['fr'], $languages->contentLanguages('fr'));
        self::assertSame(['it', 'fr'], $languages->contentLanguages('it'));
        self::assertSame(['de'], $languages->contentLanguages('de'));

        self::assertSame('/en/about-us', $languages->get('en')->path('about-us'));
        self::assertSame('/en', $languages->get('en')->path(''));
        self::assertSame('/', $languages->get('de')->path(''));

        $match = static fn(string $host, string $path): ?string => null === ($found = $languages->match($host, $path)) ? null : $found[0]->code . ':' . $found[1];
        self::assertSame('de:ueber-uns/team', $match('example.org', '/ueber-uns/team'));
        self::assertSame('en:about-us', $match('example.org', '/en/about-us'));
        self::assertSame('en:', $match('example.org', '/en'));
        self::assertSame('de:english', $match('example.org', '/english'));
        self::assertSame('nl:over-ons', $match('example.nl', '/over-ons'));
        self::assertSame('nl:en/x', $match('example.nl:8080', '/en/x'), 'Other languages are not on a domain of their own.');
    }

    public function testChoosesTheEntryLanguage(): void
    {
        $languages = self::languages(['de' => ['title' => 'Deutsch', 'prefix' => 'de'], 'en' => ['title' => 'English'], 'de-ch' => ['title' => 'Schweiz']]);

        self::assertNull($languages->match('example.org', '/'), 'Without a language without prefix "/" has no language.');
        self::assertSame('en', $languages->preferred('example.org', 'en-US,en;q=0.9,de;q=0.8')->code);
        self::assertSame('de-ch', $languages->preferred('example.org', 'fr;q=0.9, de-CH')->code);
        self::assertSame('de', $languages->preferred('example.org', 'de-AT')->code);
        self::assertSame('de', $languages->preferred('example.org', 'fr, en;q=0')->code, 'Without a match the first one.');
        self::assertSame('de', $languages->preferred('example.org', '')->code);
    }

    public function testExplainsMistakesInUrls(): void
    {
        $cases = [
            'same prefix' => [['de' => ['title' => 'D'], 'en' => ['title' => 'E', 'prefix' => '']], 'The languages "de" and "en" have the same prefix'],
            'reserved prefix' => [['de' => ['title' => 'D'], 'en' => ['title' => 'E', 'prefix' => 'api']], 'The prefix "api" of the language "en"'],
            'bad domain' => [['de' => ['title' => 'D', 'domain' => 'https://example.com']], 'The domain "https://example.com" of the language "de"'],
            'unknown fallback' => [['de' => ['title' => 'D'], 'en' => ['title' => 'E', 'fallback' => 'fr']], 'falls back to "fr", which is not a language'],
            'circle' => [['de' => ['title' => 'D', 'fallback' => 'en'], 'en' => ['title' => 'E']], 'The fallbacks of the language "de" go in a circle'],
            'fallback type' => [['de' => ['title' => 'D'], 'en' => ['title' => 'E', 'fallback' => true]], '"fallback" a language code or false'],
        ];

        foreach ($cases as $name => [$configured, $message]) {
            try {
                self::languages($configured);
                self::fail(\sprintf('"%s" was accepted.', $name));
            } catch (ConfigException $error) {
                self::assertStringContainsString($message, $error->getMessage(), $name);
            }
        }
    }

    /**
     * @param array<mixed> $configured
     */
    private static function languages(array $configured): Languages
    {
        return Languages::fromConfig(new Config(Environment::Development, ['languages' => $configured]));
    }
}
