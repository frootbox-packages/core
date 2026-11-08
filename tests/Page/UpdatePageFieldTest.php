<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Page;

use Frootbox\Core\Action\AccessDeniedException;
use Frootbox\Core\Action\Channel;
use Frootbox\Core\Action\ConflictException;
use Frootbox\Core\Action\Context;
use Frootbox\Core\Action\InvalidInputException;
use Frootbox\Core\Action\Undo\UndoAction;
use Frootbox\Core\Auth\Actor;
use Frootbox\Core\Error\Problem;
use Frootbox\Core\Extension\Extensions;
use Frootbox\Core\Page\ContentField;
use Frootbox\Core\Page\CreatePage;
use Frootbox\Core\Page\FieldUpdated;
use Frootbox\Core\Page\PageReader;
use Frootbox\Core\Page\PublishPages;
use Frootbox\Core\Page\UpdatePageField;
use Frootbox\Core\Tests\Support\FixtureSite;
use Frootbox\Core\Tests\Support\NotesTestCase;
use Frootbox\Core\User\AssignRole;
use Frootbox\Core\User\CreateUser;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(UpdatePageField::class)]
#[CoversClass(ContentField::class)]
#[CoversClass(FieldUpdated::class)]
#[CoversClass(ConflictException::class)]
final class UpdatePageFieldTest extends NotesTestCase
{
    private string $start;

    private string $teaser;

    private string $hero;

    protected function setUp(): void
    {
        parent::setUp();

        $this->start = $this->runner()->run(new CreatePage('acme/base/Page', ['de' => ['title' => 'Start', 'content' => ['fields' => ['heading' => 'Willkommen'], 'areas' => ['main' => [
            ['block' => 'acme/base/Columns', 'areas' => ['left' => [['block' => 'acme/base/Teaser', 'fields' => ['title' => 'Frisch']]]]],
            ['block' => 'acme/site/Hero', 'fields' => ['slides' => [['title' => 'Eins'], ['title' => 'Zwei']]]],
        ]]]]]))->id;
        $this->runner()->run(new PublishPages([$this->start]));

        $this->teaser = $this->text('areas/main/0/areas/left/0/id');
        $this->hero = $this->text('areas/main/1/id');
    }

    public function testChangesOneFieldOfTheLayoutOrABlock(): void
    {
        $layout = $this->runner()->run(new UpdatePageField($this->start, 'de', 'heading', 'Hallo <Welt>', 'Willkommen'));

        self::assertSame('Hallo <Welt>', $layout->value);
        self::assertSame('Hallo &lt;Welt&gt;', $layout->html);
        self::assertSame($this->lastEntry()->id, $layout->entry);

        $nested = $this->runner()->run(new UpdatePageField($this->start, 'de', 'title', 'Geröstet', 'Frisch', $this->teaser));
        self::assertSame($this->teaser, $nested->block);

        $entry = $this->runner()->run(new UpdatePageField($this->start, 'de', 'slides/1/title', 'Drei', 'Zwei', $this->hero));
        self::assertSame('Drei', $entry->value);

        $link = $this->runner()->run(new UpdatePageField($this->start, 'de', 'text', ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Mehr']]]]], null, $this->teaser));
        self::assertSame('<p>Mehr</p>', $link->html);

        self::assertSame('Hallo <Welt>', $this->valueAt('fields/heading'));
        self::assertSame('Geröstet', $this->valueAt('areas/main/0/areas/left/0/fields/title'));
        self::assertSame([['title' => 'Eins'], ['title' => 'Drei']], self::withoutIds($this->valueAt('areas/main/1/fields/slides')));
        self::assertNotNull($this->get(PageReader::class)->published($this->start), 'Only the draft changes.');
        self::assertSame('Willkommen', $this->valueAt('fields/heading', $this->get(PageReader::class)->published($this->start)->translations['de']->content));

        // Null empties the field.
        $this->runner()->run(new UpdatePageField($this->start, 'de', 'text', null, ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Mehr']]]]], $this->teaser));
        $fields = $this->valueAt('areas/main/0/areas/left/0/fields');
        self::assertIsArray($fields);
        self::assertArrayNotHasKey('text', $fields);
    }

    public function testRefusesAFieldThatChangedMeanwhile(): void
    {
        $this->runner()->run(new UpdatePageField($this->start, 'de', 'title', 'Von Jane', 'Frisch', $this->teaser));

        try {
            $this->runner()->run(new UpdatePageField($this->start, 'de', 'title', 'Von Joe', 'Frisch', $this->teaser));
            self::fail('The change of Jane was overwritten.');
        } catch (ConflictException $error) {
            self::assertSame(409, Problem::from($error, false)->status);
        }

        $this->runner()->run(new UpdatePageField($this->start, 'de', 'heading', 'Andere Stelle', 'Willkommen'));
        self::assertSame('Von Jane', $this->valueAt('areas/main/0/areas/left/0/fields/title'), 'Other fields do not get in the way.');
    }

    public function testFindsOnlyFieldsThatExist(): void
    {
        $this->assertInvalid(['/language' => 'The page has no translation in this language.'], new UpdatePageField($this->start, 'en', 'heading', 'x'));
        $this->assertInvalid(['/block' => 'There is no block with this id in the content.'], new UpdatePageField($this->start, 'de', 'title', 'x', null, 'zzzzzzzz'));
        $this->assertInvalid(['/path' => 'There is no field "nope".'], new UpdatePageField($this->start, 'de', 'nope', 'x'));
        $this->assertInvalid(['/path' => 'The list "slides" has no entry 5.'], new UpdatePageField($this->start, 'de', 'slides/5/title', 'x', null, $this->hero));
        $this->assertInvalid(['/path' => '"intro" is no list with entries to change; a path looks like "slides/0/title" or "slides/k3f9x2ab/title".'], new UpdatePageField($this->start, 'de', 'intro/0/title', 'x', null, $this->hero));

        try {
            $this->runner()->run(new UpdatePageField($this->start, 'de', 'heading', ['no' => 'text'], 'Willkommen'));
            self::fail('A wrong value was saved.');
        } catch (InvalidInputException $error) {
            self::assertArrayHasKey('/translations/de/content/fields/heading', $error->errors);
        }
    }

    public function testIsUndoneAndLimitedToTheBranch(): void
    {
        $result = $this->runner()->run(new UpdatePageField($this->start, 'de', 'heading', 'Neu', 'Willkommen'));
        self::assertNotNull($result->entry);
        $this->runner()->run(new UndoAction($result->entry));
        self::assertSame('Willkommen', $this->valueAt('fields/heading'));

        $other = $this->runner()->run(new CreatePage('acme/base/Page', ['de' => ['title' => 'Kontakt']], $this->start))->id;
        $user = $this->runner()->run(new CreateUser('jane@example.org', 'Jane', 'kaffeebohne42'))->user;
        $this->runner()->run(new AssignRole($user->id, 'editor', 'page:' . $other));
        $editor = new Context(Actor::user($user->id, $user->name), Channel::Api);

        $this->expectException(AccessDeniedException::class);
        $this->runner()->run(new UpdatePageField($this->start, 'de', 'heading', 'Fremd', 'Willkommen'), $editor);
    }

    protected function extensions(): Extensions
    {
        return FixtureSite::extensions('base', 'site');
    }

    protected function config(): array
    {
        return ['app' => ['url' => 'https://example.org'], 'theme' => 'acme/site', 'languages' => ['de' => ['title' => 'Deutsch']]];
    }

    /**
     * @param array<string, string> $errors
     */
    private function assertInvalid(array $errors, UpdatePageField $action): void
    {
        try {
            $this->runner()->run($action);
            self::fail('The field was found.');
        } catch (InvalidInputException $error) {
            self::assertSame($errors, $error->errors);
        }
    }

    /**
     * The value at a path such as "areas/main/0/id" in the content of the
     * draft, or of the given content.
     */
    private function valueAt(string $path, mixed $content = null): mixed
    {
        $value = $content ?? $this->content();

        foreach (explode('/', $path) as $key) {
            self::assertIsArray($value, $path);
            self::assertArrayHasKey($key, $value, $path);
            $value = $value[$key];
        }

        return $value;
    }

    private function text(string $path): string
    {
        $value = $this->valueAt($path);
        self::assertIsString($value, $path);

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function content(): array
    {
        $draft = $this->get(PageReader::class)->draft($this->start) ?? $this->get(PageReader::class)->published($this->start);
        self::assertNotNull($draft);

        return $draft->translations['de']->content;
    }
}
