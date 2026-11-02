<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\I18n;

use Frootbox\Core\Action\Channel;
use Frootbox\Core\Action\Context;
use Frootbox\Core\Action\CurrentContext;
use Frootbox\Core\Auth\Actor;
use Frootbox\Core\Config\Config;
use Frootbox\Core\Config\Environment;
use Frootbox\Core\Extension\ExtensionPackage;
use Frootbox\Core\Extension\Extensions;
use Frootbox\Core\I18n\Catalogue;
use Frootbox\Core\I18n\Translator;
use Frootbox\Core\I18n\UserLanguages;
use Frootbox\Core\Language\Languages;
use Frootbox\Core\ProjectPaths;
use Frootbox\Core\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

#[CoversClass(Translator::class)]
#[CoversClass(Catalogue::class)]
final class TranslatorTest extends TestCase
{
    private TemporaryProject $project;

    private CurrentContext $context;

    protected function setUp(): void
    {
        $this->project = TemporaryProject::create();
        $this->context = new CurrentContext();
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testTheUserChoosesTheLanguage(): void
    {
        $translator = $this->translator(['languages' => ['de' => ['title' => 'Deutsch']]], [1 => 'en', 2 => 'fr', 3 => null]);

        self::assertSame('de', $translator->language(), 'Guests get the standard.');
        $this->signIn(1);
        self::assertSame('en', $translator->language());
        self::assertSame('Title', $translator->get('Title'));
        $this->signIn(2);
        self::assertSame('de', $translator->language(), 'A language without texts falls back to the standard.');
        $this->signIn(3);
        self::assertSame('de', $translator->language(), 'Automatic.');
        self::assertSame('Titel', $translator->get('Title'));
    }

    public function testListsTheLanguagesWithTexts(): void
    {
        self::assertSame(['de' => 'Deutsch', 'en' => 'English', 'nl' => 'Nederlands'], $this->translator()->available(), 'Texts of a theme for visitors, here in Arabic, and those of a module in translations/website/, here in Hebrew, do not make a language of the backend.');
        self::assertTrue($this->translator()->offers('nl'));
        self::assertFalse($this->translator()->offers('fr'));
    }

    public function testTextsOnlyForVisitorsServeTheWebsite(): void
    {
        $translator = $this->translator(['languages' => ['he' => ['title' => 'עברית']]]);

        self::assertSame('כתבות ישנות', $translator->in('he', 'Older articles', ['acme/look']));
        self::assertSame('en', $translator->language(), 'The backend does not speak Hebrew because of them.');
    }

    public function testTranslatesLabelsByTheirPackageFirst(): void
    {
        $german = $this->translator(['backend' => ['language' => 'de']]);

        self::assertSame('Überschrift', $german->label('Title', 'acme/theme'));
        self::assertSame('Titel', $german->label('Title'), 'Without a package the last text wins.');
        self::assertSame('Titel', $german->label('Title', 'acme/other'));
        self::assertSame('Dachzeile', $german->label('Dachzeile', 'acme/theme'), 'A label without translation stays as written.');
        self::assertSame('', $german->label(''));
    }

    public function testTextsOfAThemeAnswerOnlyForThemes(): void
    {
        $german = $this->translator(['backend' => ['language' => 'de']]);

        self::assertSame('Titel', $german->get('Title'), 'A theme does not change a text of the backend.');
        self::assertSame('Titel', $german->label('Title', 'acme/other'), 'Nor a label of another package.');
        self::assertSame('Bezeichnung', $german->label('Title', 'acme/look'));
        self::assertSame('Bezeichnung', $german->in('de', 'Title', ['acme/look']));
        self::assertSame('Menü', $german->in('de', 'Menu', ['acme/look']), 'A theme still takes texts of others it does not have.');
        self::assertSame('Bezeichnung', $this->translator(['backend' => ['language' => 'de']], environment: Environment::Production)->label('Title', 'acme/look'));
        self::assertSame('Titel', $this->translator(['backend' => ['language' => 'de']], environment: Environment::Production)->get('Title'), 'Also from the cache.');
    }

    public function testEnglishCanHaveTextsToo(): void
    {
        self::assertSame('Eyebrow', $this->translator(['backend' => ['language' => 'en']])->label('Dachzeile', 'acme/theme'));
    }

    public function testCachesLive(): void
    {
        self::assertSame('Überschrift', $this->translator(['backend' => ['language' => 'de']], environment: Environment::Production)->label('Title', 'acme/theme'));
        self::assertFileExists($this->project->root . '/var/cache/translations-de.cache');
        self::assertSame('Überschrift', $this->translator(['backend' => ['language' => 'de']], environment: Environment::Production)->label('Title', 'acme/theme'), 'From the cache.');
    }

    private function signIn(int $id): void
    {
        $this->context->set(new Context(Actor::user($id, 'User ' . $id), Channel::Web));
    }

    /**
     * @param array<string, mixed>     $values
     * @param array<int, string|null> $languages Of the users by id
     */
    private function translator(array $values = [], array $languages = [], Environment $environment = Environment::Development): Translator
    {
        $config = new Config($environment, $values);
        $users = new class ($languages) implements UserLanguages {
            /**
             * @param array<int, string|null> $languages
             */
            public function __construct(private array $languages) {}

            public function of(int $userId): ?string
            {
                return $this->languages[$userId] ?? null;
            }
        };

        return new Translator($config, Languages::fromConfig($config), new Extensions([
            $this->package('acme/core', ['de' => ['Title' => 'Titel', 'Menu' => 'Menü'], 'nl' => ['Title' => 'Titel']]),
            $this->package('acme/theme', ['de' => ['Title' => 'Überschrift'], 'en' => ['Dachzeile' => 'Eyebrow']]),
            $this->package('acme/other', ['de' => ['Title' => 'Titel'], 'website/he' => ['Older articles' => 'כתבות ישנות']]),
            $this->package('acme/look', ['ar' => ['Skip to content' => 'انتقل إلى المحتوى'], 'de' => ['Title' => 'Bezeichnung']], theme: true),
        ]), new ProjectPaths($this->project->root), $this->context, $users);
    }

    /**
     * @param array<string, array<string, string>> $texts By language
     */
    private function package(string $name, array $texts, bool $theme = false): ExtensionPackage
    {
        $folder = $this->project->root . '/' . str_replace('/', '-', $name);

        foreach ($texts as $language => $messages) {
            $file = $folder . '/translations/' . $language . '.yaml';

            if (!is_dir(\dirname($file))) {
                mkdir(\dirname($file), 0o777, true);
            }

            file_put_contents($file, Yaml::dump($messages));
        }

        return new ExtensionPackage($name, $folder, $name, theme: $theme ? [] : null);
    }
}
