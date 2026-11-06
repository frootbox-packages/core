<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Ai;

use Frootbox\Core\Action\AccessDeniedException;
use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\Channel;
use Frootbox\Core\Action\Context;
use Frootbox\Core\Action\InvalidInputException;
use Frootbox\Core\Ai\Image;
use Frootbox\Core\Ai\Provider;
use Frootbox\Core\Ai\Text;
use Frootbox\Core\Ai\UsageLog;
use Frootbox\Core\Ai\Writing\SearchSnippet;
use Frootbox\Core\Ai\Writing\Suggestion;
use Frootbox\Core\Ai\Writing\Wording;
use Frootbox\Core\Ai\Writing\WriteAltText;
use Frootbox\Core\Ai\Writing\WriteField;
use Frootbox\Core\Ai\Writing\WriteSearchSnippet;
use Frootbox\Core\Ai\Writing\WriteTask;
use Frootbox\Core\Auth\Actor;
use Frootbox\Core\Extension\Extensions;
use Frootbox\Core\Extension\Registry;
use Frootbox\Core\Media\CreateMedium;
use Frootbox\Core\Media\Uploads;
use Frootbox\Core\Page\CreatePage;
use Frootbox\Core\Page\PageReader;
use Frootbox\Core\Page\PageText;
use Frootbox\Core\Page\UpdatePageField;
use Frootbox\Core\ProjectPaths;
use Frootbox\Core\Tests\Fixtures\Ai\FakeAi;
use Frootbox\Core\Tests\Support\FixtureSite;
use Frootbox\Core\Tests\Support\NotesTestCase;
use Frootbox\Core\User\AssignRole;
use Frootbox\Core\User\CreateUser;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Writing at a field with the AI (decision 0296): suggestions only.
 */
#[CoversClass(WriteField::class)]
#[CoversClass(WriteAltText::class)]
#[CoversClass(WriteSearchSnippet::class)]
#[CoversClass(WriteTask::class)]
#[CoversClass(Wording::class)]
#[CoversClass(Suggestion::class)]
#[CoversClass(SearchSnippet::class)]
#[CoversClass(PageText::class)]
final class WritingTest extends NotesTestCase
{
    private string $page;

    private string $teaser;

    protected function setUp(): void
    {
        FakeAi::reset();
        parent::setUp();
        $this->page = $this->runner()->run(new CreatePage('acme/base/Page', [
            'de' => ['title' => 'Über uns', 'content' => ['fields' => ['heading' => 'Willkommen bei uns'], 'areas' => ['main' => [
                ['block' => 'acme/base/Teaser', 'fields' => ['title' => 'Frisch geröstet', 'text' => self::doc('Wir rösten jeden Tag.')]],
            ]]]],
            'en' => ['title' => 'About us', 'content' => ['fields' => ['heading' => ''], 'areas' => ['main' => []]]],
        ]))->id;
        $content = $this->get(PageReader::class)->draft($this->page)?->translations['de']->content ?? [];
        $areas = \is_array($content['areas'] ?? null) ? $content['areas'] : [];
        $main = \is_array($areas['main'] ?? null) ? $areas['main'] : [];
        $first = \is_array($main[0] ?? null) ? $main[0] : [];
        self::assertIsString($first['id'] ?? null);
        $this->teaser = $first['id'];
    }

    public function testSuggestsAHeadlineAndSavesNothing(): void
    {
        FakeAi::$answer = "„Frisch aus der Rösterei\nin Münster“";
        $before = $this->get(PageReader::class)->draft($this->page);

        $suggestion = $this->runner()->run(new WriteField($this->page, 'de', 'title', WriteTask::Rephrase, $this->teaser));

        self::assertSame('Frisch aus der Rösterei in Münster', $suggestion->value, 'One line, without quotation marks.');
        self::assertEquals($before, $this->get(PageReader::class)->draft($this->page), 'The draft stays as it was.');
        $prompt = $this->prompt();
        self::assertStringContainsString('Website: Testsite', $prompt);
        self::assertStringContainsString('Page: Über uns', $prompt);
        self::assertStringContainsString('at most 80 characters', $prompt, 'The limit of the field.');
        self::assertStringContainsString('Deutsch (de)', $prompt);
        self::assertStringContainsString("<text>\nFrisch geröstet\n</text>", $prompt);
        self::assertStringNotContainsString('Wir rösten', $prompt, 'Only the field goes to the provider.');
        self::assertSame(Wording::SYSTEM, FakeAi::$requests[0]->system, 'The same for every feature, so it is cached.');
        self::assertSame('field.rephrase', $this->connection()->fetchOne('SELECT feature FROM ' . UsageLog::TABLE));

        // Taking it is a normal change of the field.
        $this->runner()->run(new UpdatePageField($this->page, 'de', 'title', $suggestion->value, 'Frisch geröstet', $this->teaser));
        self::assertSame('page.update-field', $this->lastEntry()->action);
    }

    public function testRichTextComesBackAsADocumentOfTheField(): void
    {
        FakeAi::$answer = "```html\n<h2>Unsere Röstung</h2><p>Wir rösten <strong>jeden Tag</strong> frisch.</p><script>alert(1)</script>\n```";

        $suggestion = $this->runner()->run(new WriteField($this->page, 'de', 'text', WriteTask::Expand, $this->teaser));

        self::assertSame([
            ['type' => 'heading', 'attrs' => ['level' => 1], 'content' => [['type' => 'text', 'text' => 'Unsere Röstung']]],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Wir rösten '], ['type' => 'text', 'text' => 'jeden Tag', 'marks' => [['type' => 'bold']]], ['type' => 'text', 'text' => ' frisch.']]],
        ], \is_array($suggestion->value) ? $suggestion->value['content'] ?? null : null);
        self::assertStringContainsString('<p>Wir rösten jeden Tag.</p>', $this->prompt(), 'The text goes as HTML.');
        $this->runner()->run(new UpdatePageField($this->page, 'de', 'text', $suggestion->value, self::doc('Wir rösten jeden Tag.'), $this->teaser));
    }

    public function testTranslatesFromAnotherLanguageAndDraftsFromAnInstruction(): void
    {
        FakeAi::$answer = 'Welcome to us';
        $translated = $this->runner()->run(new WriteField($this->page, 'en', 'heading', WriteTask::Translate, from: 'de'));

        self::assertSame('Welcome to us', $translated->value);
        self::assertStringContainsString('Translate from Deutsch (de) into English (en).', $this->prompt());
        self::assertStringContainsString("<text>\nWillkommen bei uns\n</text>", $this->prompt());

        FakeAi::$answer = 'Roasted daily';
        $this->runner()->run(new WriteField($this->page, 'de', 'heading', WriteTask::Instruct, instruction: 'Kürzer, mit "täglich"'));
        self::assertStringContainsString("<instruction>\nKürzer, mit \"täglich\"\n</instruction>", $this->prompt());

        $this->assertInvalid('/path', new WriteField($this->page, 'en', 'heading', WriteTask::Rephrase));
        $this->assertInvalid('/from', new WriteField($this->page, 'en', 'heading', WriteTask::Translate));
        $this->assertInvalid('/from', new WriteField($this->page, 'de', 'heading', WriteTask::Translate, from: 'de'));
        $this->assertInvalid('/instruction', new WriteField($this->page, 'en', 'heading', WriteTask::Instruct));
        $this->assertInvalid('/path', new WriteField($this->page, 'de', 'button', WriteTask::Rephrase, $this->teaser));
        $this->assertInvalid('/language', new WriteField($this->page, 'fr', 'heading', WriteTask::Rephrase));
        self::assertCount(2, FakeAi::$requests, 'Refused before asking the provider.');

        FakeAi::$answer = str_repeat('Lang ', 40);
        $this->assertInvalid('', new WriteField($this->page, 'de', 'title', WriteTask::Expand, $this->teaser));
    }

    public function testSuggestsAnAltTextFromASmallerCopyOfTheImage(): void
    {
        $image = imagecreatetruecolor(2400, 1200);
        $file = $this->get(ProjectPaths::class)->var('photo.png');
        @mkdir(\dirname($file), 0o775, true);
        imagepng($image, $file);
        $medium = $this->runner()->run(new CreateMedium($this->get(Uploads::class)->store($file, 'kaffee.png')));
        FakeAi::$answer = 'Eine Tasse Kaffee auf einem Holztisch.';

        $suggestion = $this->runner()->run(new WriteAltText($medium->id, 'de', $this->page));

        self::assertSame('Eine Tasse Kaffee auf einem Holztisch.', $suggestion->value);
        $parts = FakeAi::$requests[0]->messages[0]->parts;
        self::assertInstanceOf(Image::class, $parts[0]);
        self::assertSame('image/jpeg', $parts[0]->mimeType);
        $size = getimagesizefromstring($parts[0]->bytes);
        self::assertIsArray($size);
        self::assertSame([WriteAltText::SIZE, 784], [$size[0], $size[1]], 'At most 1568 pixels.');
        self::assertInstanceOf(Text::class, $parts[1]);
        self::assertStringContainsString('Page: Über uns', $parts[1]->text);
        self::assertSame('media.alt-text', $this->connection()->fetchOne('SELECT feature FROM ' . UsageLog::TABLE));
    }

    public function testSuggestsTitleAndDescriptionForSearchEngines(): void
    {
        FakeAi::$answer = 'Here you go: {"title": "Über uns: Rösterei in Münster", "description": "Wir rösten jeden Tag frisch in Münster. Lerne die Rösterei kennen und\nbesuche uns."}';

        $snippet = $this->runner()->run(new WriteSearchSnippet($this->page, 'de', 'Münster nennen'));

        self::assertSame('Über uns: Rösterei in Münster', $snippet->title);
        self::assertSame('Wir rösten jeden Tag frisch in Münster. Lerne die Rösterei kennen und besuche uns.', $snippet->description);
        $prompt = $this->prompt();
        self::assertStringContainsString("<page>\nWillkommen bei uns\nFrisch geröstet\nWir rösten jeden Tag.\n</page>", $prompt, 'The text of the draft.');

        FakeAi::$answer = 'Sorry.';
        $this->assertInvalid('', new WriteSearchSnippet($this->page, 'de'));
    }

    public function testOnlyWithThePermissionToUseTheAi(): void
    {
        $user = $this->runner()->run(new CreateUser('editor@example.org', 'Eddie', 'kaffeebohne42'))->user;
        $this->runner()->run(new AssignRole($user->id, 'editor'));

        $this->expectException(AccessDeniedException::class);
        $this->runner()->run(new WriteField($this->page, 'de', 'heading', WriteTask::Rephrase), new Context(Actor::user($user->id, 'Eddie'), Channel::Web));
    }

    protected function extensions(): Extensions
    {
        return FixtureSite::extensions('base', 'site');
    }

    protected function config(): array
    {
        return ['app' => ['name' => 'Testsite', 'url' => 'https://example.org'], 'theme' => 'acme/site', 'languages' => ['de' => ['title' => 'Deutsch'], 'en' => ['title' => 'English']]];
    }

    protected function register(Registry $registry): void
    {
        $registry->forPackage('acme/fake-ai', static fn() => $registry->add(Provider::class, new Provider('acme/fake-ai', 'Fake', FakeAi::class)));
    }

    /**
     * @return array<string, mixed>
     */
    private static function doc(string $text): array
    {
        return ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]]]];
    }

    private function prompt(): string
    {
        $request = array_last(FakeAi::$requests);
        self::assertNotNull($request);
        $part = array_last($request->messages[0]->parts);
        self::assertInstanceOf(Text::class, $part);

        return $part->text;
    }

    /**
     * @param Action<mixed> $action
     */
    private function assertInvalid(string $pointer, Action $action): void
    {
        try {
            $this->runner()->run($action);
            self::fail('Expected an error at ' . $pointer);
        } catch (InvalidInputException $error) {
            self::assertArrayHasKey($pointer, $error->errors, (string) json_encode($error->errors));
        }
    }
}
