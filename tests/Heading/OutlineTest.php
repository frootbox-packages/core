<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Heading;

use Frootbox\Core\Action\InvalidInputException;
use Frootbox\Core\Config\Environment;
use Frootbox\Core\Extension\Extensions;
use Frootbox\Core\Heading\OutlineHeading;
use Frootbox\Core\Heading\OutlineReader;
use Frootbox\Core\Heading\ShowOutline;
use Frootbox\Core\Page\CreatePage;
use Frootbox\Core\Page\EditMode;
use Frootbox\Core\Page\PublishPages;
use Frootbox\Core\Page\UpdatePageField;
use Frootbox\Core\Tests\Support\FixtureSite;
use Frootbox\Core\Tests\Support\NotesTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(ShowOutline::class)]
#[CoversClass(OutlineReader::class)]
#[CoversClass(OutlineHeading::class)]
final class OutlineTest extends NotesTestCase
{
    public function testListsTheHeadingsWithTheirLevelBlockAndNotes(): void
    {
        $id = $this->page('acme/outline/Page', 'Über uns', ['title' => 'Über uns', 'intro' => self::richText([3, 'Tiny'])], [
            ['block' => 'acme/outline/Faq', 'fields' => ['title' => 'Fragen', 'items' => [['question' => 'Bio?'], ['question' => 'Fair & direkt?']]]],
        ]);
        $outline = $this->runner()->ask(new ShowOutline($id));
        $faq = $outline[2]->blockId;

        self::assertNotNull($faq);
        self::assertEquals([
            new OutlineHeading(1, 'Über uns', 0, null, 'title'),
            new OutlineHeading(2, 'Tiny', 3, null, 'intro', [OutlineHeading::ADJUSTED]),
            new OutlineHeading(2, 'Fragen', 0, $faq, 'title'),
            new OutlineHeading(3, 'Bio?', 1, $faq, 'items/0/question'),
            new OutlineHeading(3, 'Fair & direkt?', 1, $faq, 'items/1/question'),
            new OutlineHeading(2, 'Contact', notes: [OutlineHeading::FIXED]),
        ], $outline);
    }

    public function testNamesTheTitleTheCoreAddsAndReadsTheDraftOrThePublishedPage(): void
    {
        $id = $this->page('acme/outline/OnePage', 'Bilder', [], [['block' => 'acme/outline/Image']]);
        $this->runner()->run(new PublishPages([$id]));

        self::assertEquals([new OutlineHeading(1, 'Bilder', notes: [OutlineHeading::ADDED])], $this->runner()->ask(new ShowOutline($id, 'de', false)));

        $this->runner()->run(new UpdatePageField($id, 'de', 'caption', 'Neu', block: $this->blockId($id)));
        self::assertCount(1, $this->runner()->ask(new ShowOutline($id)));
        self::assertFalse($this->get(EditMode::class)->active(), 'The mode is as it was.');

        try {
            $this->runner()->ask(new ShowOutline($id, 'en'));
            self::fail('Accepted a language the website does not have.');
        } catch (InvalidInputException $error) {
            self::assertArrayHasKey('/language', $error->errors);
        }
    }

    protected function environment(): Environment
    {
        return Environment::Production;
    }

    protected function extensions(): Extensions
    {
        return FixtureSite::extensions('outline');
    }

    protected function config(): array
    {
        return ['theme' => 'acme/outline', 'languages' => ['de' => ['title' => 'Deutsch']]];
    }

    /**
     * @param array<string, mixed>       $fields
     * @param list<array<string, mixed>> $main
     */
    private function page(string $layout, string $title, array $fields, array $main): string
    {
        return $this->runner()->run(new CreatePage($layout, ['de' => ['title' => $title, 'content' => ['fields' => $fields, 'areas' => ['main' => $main]]]]))->id;
    }

    private function blockId(string $page): string
    {
        $content = $this->connection()->fetchOne('SELECT data FROM revisions WHERE entity_id = ? AND status = ?', [$page, 'draft']);
        $content = \is_string($content) ? $content : $this->connection()->fetchOne('SELECT content FROM page_translations WHERE page_id = ?', [$page]);
        self::assertIsString($content);
        self::assertSame(1, preg_match('/"id":"([^"]+)"/', $content, $match));

        return $match[1];
    }

    /**
     * @param array{int, string} ...$headings rank and text
     *
     * @return array<string, mixed>
     */
    private static function richText(array ...$headings): array
    {
        return ['type' => 'doc', 'content' => array_map(static fn(array $heading): array => ['type' => 'heading', 'attrs' => ['level' => $heading[0]], 'content' => [['type' => 'text', 'text' => $heading[1]]]], $headings)];
    }
}
