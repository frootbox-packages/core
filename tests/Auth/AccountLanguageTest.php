<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Auth;

use Frootbox\Core\Account\AccountLanguages;
use Frootbox\Core\Account\ListLanguages;
use Frootbox\Core\Account\UpdateLanguage;
use Frootbox\Core\Action\Channel;
use Frootbox\Core\Action\Context;
use Frootbox\Core\Action\CurrentContext;
use Frootbox\Core\Action\InvalidInputException;
use Frootbox\Core\Auth\Actor;
use Frootbox\Core\Config\Config;
use Frootbox\Core\Extension\ExtensionPackage;
use Frootbox\Core\Extension\Extensions;
use Frootbox\Core\I18n\Translator;
use Frootbox\Core\Language\Languages;
use Frootbox\Core\ProjectPaths;
use Frootbox\Core\Tests\Support\NotesTestCase;
use Frootbox\Core\User\CreateUser;
use Frootbox\Core\User\StoredUserLanguages;
use Frootbox\Core\User\User;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(UpdateLanguage::class)]
#[CoversClass(ListLanguages::class)]
#[CoversClass(AccountLanguages::class)]
#[CoversClass(StoredUserLanguages::class)]
final class AccountLanguageTest extends NotesTestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->runner()->run(new CreateUser('jane@example.com', 'Jane', 'correct horse battery'))->user;
    }

    public function testEachUserChoosesTheLanguageOfTheBackend(): void
    {
        $context = new Context(Actor::user($this->user->id, $this->user->name), Channel::Web);
        $languages = $this->runner()->ask(new ListLanguages(), $context);

        self::assertSame([['code' => 'de', 'name' => 'Deutsch'], ['code' => 'en', 'name' => 'English']], $languages->languages);
        self::assertNull($languages->chosen);
        self::assertSame('de', $languages->standard, 'The first language of the website has texts.');
        self::assertSame('de', $languages->current);

        self::assertSame('en', $this->runner()->run(new UpdateLanguage('en'), $context)->language);
        $this->get(CurrentContext::class)->set($context);
        self::assertSame('Pages', $this->freshTranslator()->get('Pages'));
        self::assertSame('en', $this->runner()->ask(new ListLanguages(), $context)->chosen);

        self::assertNull($this->runner()->run(new UpdateLanguage(null), $context)->language);
        self::assertSame('Seiten', $this->freshTranslator()->get('Pages'), 'Automatic again.');

        try {
            $this->runner()->run(new UpdateLanguage('fr'), $context);
            self::fail('Accepted a language without texts.');
        } catch (InvalidInputException $error) {
            self::assertSame('must be one of de, en, or null for automatic.', $error->errors['/language']);
        }
    }

    protected function extensions(): Extensions
    {
        return new Extensions([new ExtensionPackage('acme/texts', __DIR__ . '/../Fixtures/Texts', 'Texts')]);
    }

    protected function config(): array
    {
        return ['languages' => ['de' => ['title' => 'Deutsch']]];
    }

    /** The translator keeps the language of a user for the request, a new request asks again. */
    private function freshTranslator(): Translator
    {
        return new Translator(
            $this->get(Config::class),
            $this->get(Languages::class),
            $this->get(Extensions::class),
            $this->get(ProjectPaths::class),
            $this->get(CurrentContext::class),
            $this->get(StoredUserLanguages::class),
        );
    }
}
