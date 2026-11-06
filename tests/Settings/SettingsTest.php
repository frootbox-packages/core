<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Settings;

use Frootbox\Core\Action\DefinitionException;
use Frootbox\Core\Action\InvalidInputException;
use Frootbox\Core\Action\Undo\UndoAction;
use Frootbox\Core\Consent\ConsentSettings;
use Frootbox\Core\Extension\Registry;
use Frootbox\Core\Mail\MailSettings;
use Frootbox\Core\Security\Secrets;
use Frootbox\Core\Settings\AsSettings;
use Frootbox\Core\Settings\ListSettings;
use Frootbox\Core\Settings\SetSecret;
use Frootbox\Core\Settings\Settings;
use Frootbox\Core\Settings\SettingsClasses;
use Frootbox\Core\Settings\ShowSettings;
use Frootbox\Core\Settings\UpdateSettings;
use Frootbox\Core\Tests\Fixtures\Notes\Note;
use Frootbox\Core\Tests\Fixtures\Notes\NotesSettings;
use Frootbox\Core\Tests\Support\NotesTestCase;
use Frootbox\Database\EntityNotFoundException;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(Settings::class)]
#[CoversClass(UpdateSettings::class)]
#[CoversClass(SetSecret::class)]
#[CoversClass(ShowSettings::class)]
#[CoversClass(ListSettings::class)]
#[CoversClass(SettingsClasses::class)]
#[CoversClass(Secrets::class)]
final class SettingsTest extends NotesTestCase
{
    public function testAnExtensionGetsItsDefaultsInjected(): void
    {
        $settings = $this->get(NotesSettings::class);

        self::assertSame('notes@example.test', $settings->sender);
        self::assertSame(20, $settings->perPage);
        self::assertSame('', $settings->apiKey);
    }

    public function testDescribesTheSettingsWithTheirSchema(): void
    {
        $details = $this->runner()->ask(new ShowSettings('acme/notes'));

        self::assertSame('Notes', $details->title);
        self::assertSame(['sender', 'perPage', 'ratio', 'archive', 'footer', 'apiKey'], array_column(array_map(static fn(object $field): array => (array) $field, $details->fields), 'name'));
        $sender = $details->fields[0];
        self::assertSame('Sender', $sender->title);
        self::assertSame('The address notes come from', $sender->description);
        self::assertSame('notes@example.test', $sender->value);
        self::assertSame(100, $sender->schema['maxLength']);
        self::assertSame('ratio', $details->fields[2]->title, 'Without #[Title] the name is the title.');
        self::assertTrue($details->fields[5]->secret);
        self::assertFalse($details->fields[5]->set);
        self::assertSame(['acme/notes', 'frootbox/core', 'frootbox/core.ai', 'frootbox/core.mail'], array_map(static fn(object $settings): string => $settings->package, $this->runner()->ask(new ListSettings())), 'The core has settings about consent (decision 0264), the AI (decision 0294) and mail (decision 0269).');
    }

    public function testChangesValuesAndResetsThemToTheDefault(): void
    {
        $details = $this->runner()->run(new UpdateSettings('acme/notes', ['sender' => 'hello@example.test', 'perPage' => 5, 'ratio' => 2, 'archive' => true, 'footer' => 'Bye']));

        self::assertSame('hello@example.test', $details->fields[0]->value);
        $settings = $this->settings()->get(NotesSettings::class);
        self::assertSame(5, $settings->perPage);
        self::assertSame(2.0, $settings->ratio);
        self::assertTrue($settings->archive);
        self::assertSame('Bye', $settings->footer);
        self::assertSame(5, $this->rows());

        // The default removes the stored value, undo brings it back.
        $this->runner()->run(new UpdateSettings('acme/notes', ['perPage' => 20, 'footer' => null]));
        self::assertSame(3, $this->rows());
        $this->runner()->run(new UndoAction($this->lastEntry()->id));
        $this->settings()->reset();
        self::assertSame(5, $this->settings()->get(NotesSettings::class)->perPage);
        self::assertSame(5, $this->rows());
    }

    public function testRejectsWrongValues(): void
    {
        foreach ([
            [['perPage' => 0], '/values/perPage'],
            [['perPage' => 'many'], '/values/perPage'],
            [['colour' => 'red'], '/values/colour'],
            [['apiKey' => 'abc'], '/values/apiKey'],
        ] as [$values, $pointer]) {
            try {
                $this->runner()->run(new UpdateSettings('acme/notes', $values));
                self::fail('Expected an error for ' . $pointer);
            } catch (InvalidInputException $error) {
                self::assertArrayHasKey($pointer, $error->errors);
            }
        }

        $this->expectException(EntityNotFoundException::class);
        $this->runner()->run(new UpdateSettings('acme/nothing', ['a' => 1]));
    }

    public function testKeepsSecretsEncryptedAndNeverShowsThem(): void
    {
        $details = $this->runner()->run(new SetSecret('acme/notes', 'apiKey', 'sk-live-123'));

        self::assertTrue($details->fields[5]->set);
        self::assertNull($details->fields[5]->value);
        self::assertSame('sk-live-123', $this->settings()->get(NotesSettings::class)->apiKey);
        $stored = $this->connection()->fetchAssociative('SELECT value, secret FROM settings');
        self::assertIsArray($stored);
        self::assertNull($stored['value']);
        self::assertIsString($stored['secret']);
        self::assertStringNotContainsString('sk-live-123', $stored['secret']);
        self::assertStringNotContainsString('sk-live-123', (string) json_encode($this->lastEntry()));
        self::assertFalse($this->lastEntry()->undoable);

        // Encrypted for settings, it cannot be read for anything else.
        self::assertNull($this->get(Secrets::class)->decrypt('preview-links', $stored['secret']));

        $this->runner()->run(new SetSecret('acme/notes', 'apiKey', null));
        self::assertSame('', $this->settings()->get(NotesSettings::class)->apiKey);
        self::assertSame(0, $this->rows());

        $this->expectException(InvalidInputException::class);
        $this->runner()->run(new SetSecret('acme/notes', 'sender', 'x'));
    }

    public function testASecretThatCannotBeReadCountsAsNotSet(): void
    {
        $this->connection()->insert('settings', ['package' => 'acme/notes', 'name' => 'apiKey', 'secret' => 'v1:broken']);

        self::assertSame('', $this->settings()->get(NotesSettings::class)->apiKey);
        self::assertFalse($this->runner()->ask(new ShowSettings('acme/notes'))->fields[5]->set);
    }

    public function testAStoredValueOfAnotherTypeFallsBackToTheDefault(): void
    {
        $this->connection()->insert('settings', ['package' => 'acme/notes', 'name' => 'perPage', 'value' => '"ten"']);

        self::assertSame(20, $this->settings()->get(NotesSettings::class)->perPage);
    }

    public function testASettingsClassNeedsDefaults(): void
    {
        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage('needs the attribute');

        new Registry()->settings(Note::class);
    }

    public function testAPackageHasOneSettingsClassPerSection(): void
    {
        $registry = new Registry();
        $registry->settings(ConsentSettings::class);
        $registry->settings(MailSettings::class);

        self::assertSame(['frootbox/core' => ConsentSettings::class, 'frootbox/core.mail' => MailSettings::class], $registry->settingsClasses()->all());

        try {
            $registry->settings(NotesSettings::class);
            self::fail('The core has its class without a section.');
        } catch (DefinitionException $error) {
            self::assertStringContainsString('one settings class per section', $error->getMessage());
        }

        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage('may only have lowercase letters');
        $settings = new #[AsSettings('Mail', section: 'Mail')] readonly class {};
        $registry->settings($settings::class);
    }

    private function settings(): Settings
    {
        return $this->get(Settings::class);
    }

    private function rows(): int
    {
        $count = $this->connection()->fetchOne('SELECT COUNT(*) FROM settings');

        return is_numeric($count) ? (int) $count : -1;
    }
}
