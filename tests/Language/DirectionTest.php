<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Language;

use Frootbox\Core\Config\Config;
use Frootbox\Core\Config\ConfigException;
use Frootbox\Core\Config\Environment;
use Frootbox\Core\Extension\Extensions;
use Frootbox\Core\Language\Direction;
use Frootbox\Core\Language\Language;
use Frootbox\Core\Language\Languages;
use Frootbox\Core\Page\CreatePage;
use Frootbox\Core\Page\PageHandler;
use Frootbox\Core\Page\PublishPages;
use Frootbox\Core\Tests\Support\FixtureSite;
use Frootbox\Core\Tests\Support\NotesTestCase;
use Frootbox\Core\View\Document;
use Frootbox\Core\View\ViewExtension;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(Direction::class)]
#[CoversClass(Language::class)]
#[CoversClass(Languages::class)]
#[CoversClass(Document::class)]
#[CoversClass(ViewExtension::class)]
final class DirectionTest extends NotesTestCase
{
    public function testTheCodeGivesTheDirection(): void
    {
        self::assertSame(Direction::Rtl, Direction::of('ar'));
        self::assertSame(Direction::Rtl, Direction::of('fa-af'));
        self::assertSame(Direction::Rtl, Direction::of('he'));
        self::assertSame(Direction::Ltr, Direction::of('de'));
        self::assertSame(Direction::Ltr, Direction::of('arn'), 'Only the whole first part counts.');
    }

    public function testTheConfigOverridesIt(): void
    {
        $languages = Languages::fromConfig(new Config(Environment::Production, ['languages' => [
            'de' => ['title' => 'Deutsch'],
            'ar' => ['title' => 'العربية'],
            'xx' => ['title' => 'Beispiel', 'direction' => 'rtl'],
            'ur' => ['title' => 'Romanized', 'direction' => 'ltr'],
        ]]));

        self::assertSame(['ltr', 'rtl', 'rtl', 'ltr'], array_map(static fn(Language $language): string => $language->direction->value, $languages->all()));

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('"direction" must be "ltr" or "rtl"');
        Languages::fromConfig(new Config(Environment::Production, ['languages' => ['de' => ['title' => 'Deutsch', 'direction' => 'left']]]));
    }

    public function testPagesCarryTheDirectionOfTheirLanguage(): void
    {
        $start = $this->runner()->run(new CreatePage('acme/rtl/Page', [
            'de' => ['title' => 'Start', 'content' => ['fields' => ['heading' => 'Willkommen']]],
            'ar' => ['title' => 'الرئيسية', 'content' => ['fields' => ['heading' => 'أهلا وسهلا']]],
        ]))->id;
        $team = $this->runner()->run(new CreatePage('acme/rtl/Page', [
            'de' => ['title' => 'Team', 'content' => ['fields' => ['heading' => 'Unser Team'], 'areas' => ['main' => [['block' => 'acme/base/Teaser', 'fields' => ['title' => 'Wir']]]]]],
        ], $start))->id;
        $this->runner()->run(new PublishPages([$start, $team]));

        $arabic = $this->html('/ar');
        self::assertStringStartsWith("<!doctype html>\n<html lang=\"ar\" dir=\"rtl\">", $arabic);
        self::assertStringContainsString('<nav data-direction="rtl" data-content-direction="rtl"><a href="/" lang="de" dir="ltr">Deutsch</a><a href="/ar" lang="ar" dir="rtl">العربية</a></nav>', $arabic);
        self::assertStringContainsString('<h1 class="title">أهلا وسهلا</h1><p class="intro">', $arabic, 'Nothing to mark in its own language.');

        // German content in the Arabic frame: language and direction marked.
        $fallback = $this->html('/ar/team');
        self::assertStringContainsString('<nav data-direction="rtl" data-content-direction="ltr">', $fallback);
        self::assertStringContainsString('<h1 class="title" lang="de" dir="ltr">Unser Team</h1><p class="intro" lang="de" dir="ltr">', $fallback);
        self::assertStringContainsString('<section lang="de" dir="ltr" class="teaser"', $fallback, 'Blocks too.');

        self::assertStringStartsWith("<!doctype html>\n<html lang=\"de\" dir=\"ltr\">", $this->html('/team'));
    }

    protected function extensions(): Extensions
    {
        return FixtureSite::extensions('base', 'rtl');
    }

    protected function config(): array
    {
        return [
            'app' => ['name' => 'Testsite', 'url' => 'https://example.org'],
            'theme' => 'acme/rtl',
            'languages' => ['de' => ['title' => 'Deutsch'], 'ar' => ['title' => 'العربية']],
        ];
    }

    private function html(string $path): string
    {
        $response = $this->get(PageHandler::class)->handle(new ServerRequest('GET', 'https://example.org' . $path));
        self::assertSame(200, $response->getStatusCode(), $path);

        return (string) $response->getBody();
    }
}
