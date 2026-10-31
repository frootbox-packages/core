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

    /**
     * @param array<mixed> $configured
     */
    private static function languages(array $configured): Languages
    {
        return Languages::fromConfig(new Config(Environment::Development, ['languages' => $configured]));
    }
}
