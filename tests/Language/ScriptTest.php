<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Language;

use Frootbox\Core\Config\Config;
use Frootbox\Core\Config\ConfigException;
use Frootbox\Core\Config\Environment;
use Frootbox\Core\Language\Language;
use Frootbox\Core\Language\Languages;
use Frootbox\Core\Language\Script;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Script::class)]
#[CoversClass(Language::class)]
#[CoversClass(Languages::class)]
final class ScriptTest extends TestCase
{
    public function testTheCodeGivesTheScript(): void
    {
        self::assertSame('arab', Script::of('ar'));
        self::assertSame('arab', Script::of('fa-af'));
        self::assertSame('hebr', Script::of('he'));
        self::assertSame('cyrl', Script::of('sr'));
        self::assertSame('latn', Script::of('sr-latn'), 'A script in the code wins.');
        self::assertSame('hans', Script::of('zh'));
        self::assertSame('hant', Script::of('zh-tw'));
        self::assertSame('thaa', Script::of('dv'));
        self::assertSame('latn', Script::of('de'));
        self::assertSame('latn', Script::of('arn'), 'Only the whole first part counts.');
    }

    public function testTheConfigOverridesIt(): void
    {
        $languages = Languages::fromConfig(new Config(Environment::Production, ['languages' => [
            'de' => ['title' => 'Deutsch'],
            'ar' => ['title' => 'العربية'],
            'az' => ['title' => 'Azərbaycan', 'script' => 'cyrl'],
        ]]));

        self::assertSame(['latn', 'arab', 'cyrl'], array_map(static fn(Language $language): string => $language->script, $languages->all()));

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('"script" must be a code of ISO 15924');
        Languages::fromConfig(new Config(Environment::Production, ['languages' => ['de' => ['title' => 'Deutsch', 'script' => 'Latin']]]));
    }
}
