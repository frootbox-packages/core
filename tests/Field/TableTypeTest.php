<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Field;

use Frootbox\Core\Field\Field;
use Frootbox\Core\Field\FieldType;
use Frootbox\Core\Field\FieldTypes;
use Frootbox\Core\Field\Types\TableType;
use Frootbox\Core\Tests\Support\NotesTestCase;
use Frootbox\RichText\Nodes\Line;
use Opis\JsonSchema\Helper;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Twig\Markup;

/**
 * Tables (decision 0187).
 */
#[CoversClass(TableType::class)]
#[CoversClass(Line::class)]
final class TableTypeTest extends NotesTestCase
{
    public function testGivesATableWithHeadsOfTheColumns(): void
    {
        $html = $this->html(['rows' => [['Tag', 'Öffnungszeit'], ['Montag', '8–18 Uhr'], ['Samstag', "9–13 Uhr\nnur im Laden"]]]);

        self::assertSame(
            '<table><thead><tr><th scope="col">Tag</th><th scope="col">Öffnungszeit</th></tr></thead>'
            . '<tbody><tr><td>Montag</td><td>8–18 Uhr</td></tr><tr><td>Samstag</td><td>9–13 Uhr<br>nur im Laden</td></tr></tbody></table>',
            $html,
        );
    }

    public function testGivesHeadsOfTheRowsAndFillsShortRows(): void
    {
        $html = $this->html(['rows' => [['Klein', '14 €', 'pro Monat'], ['Groß']], 'headerRow' => false, 'headerColumn' => true]);

        self::assertSame(
            '<table><tbody><tr><th scope="row">Klein</th><td>14 €</td><td>pro Monat</td></tr><tr><th scope="row">Groß</th><td></td><td></td></tr></tbody></table>',
            $html,
        );
    }

    public function testFormatsCellsAndLeavesOutWhatIsNotAllowed(): void
    {
        $html = $this->html(['rows' => [['Sorte', 'Preis'], [
            [['type' => 'text', 'text' => 'Hausmischung', 'marks' => [['type' => 'bold']]], ['type' => 'hardBreak'], ['type' => 'text', 'text' => 'mild', 'marks' => [['type' => 'italic']]]],
            [['type' => 'text', 'text' => 'Preisliste', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://example.org/preise.pdf']]]], ['type' => 'text', 'text' => '<script>', 'marks' => [['type' => 'underline']]]],
        ]]]);

        self::assertStringContainsString('<td><strong>Hausmischung</strong><br><em>mild</em></td>', $html);
        self::assertStringContainsString('<td><a href="https://example.org/preise.pdf">Preisliste</a>&lt;script&gt;</td>', $html, 'Escaped, and underline is no mark of a cell.');
    }

    public function testGivesNothingWithoutCells(): void
    {
        $type = $this->type();
        $field = new Field('table', 'table', 'Table');

        self::assertSame('', $type->output(null, $field));
        self::assertSame('', $type->output(['rows' => []], $field));
        self::assertSame('', $type->output(['rows' => [[], []]], $field));
    }

    /**
     * @return iterable<string, array{mixed, bool}>
     */
    public static function values(): iterable
    {
        yield 'text' => [['rows' => [['a', 'b']]], true];
        yield 'heads' => [['rows' => [['a']], 'headerRow' => false, 'headerColumn' => true], true];
        yield 'inline nodes' => [['rows' => [[[['type' => 'text', 'text' => 'a']]]]], true];
        yield 'without rows' => [['headerRow' => true], false];
        yield 'a number' => [['rows' => [[1]]], false];
        yield 'a row that is no list' => [['rows' => ['a']], false];
        yield 'too many columns' => [['rows' => [array_fill(0, 21, 'a')]], false];
        yield 'too many rows' => [['rows' => array_fill(0, 501, ['a'])], false];
        yield 'too long' => [['rows' => [[str_repeat('a', 2001)]]], false];
        yield 'something else' => [['rows' => [['a']], 'caption' => 'x'], false];
    }

    #[DataProvider('values')]
    public function testChecksTheShape(mixed $value, bool $valid): void
    {
        $schema = json_encode($this->type()->valueSchema(new Field('table', 'table', 'Table')), \JSON_THROW_ON_ERROR);

        self::assertSame($valid, new Validator()->validate(Helper::toJSON($value), $schema)->isValid());
    }

    public function testChecksTheInlineNodes(): void
    {
        $type = $this->type();
        $field = new Field('table', 'table', 'Table');

        self::assertSame([], $type->validate(['rows' => [['a', [['type' => 'text', 'text' => 'b', 'marks' => [['type' => 'bold']]]]]]], $field));

        $errors = $type->validate(['rows' => [['a'], ['b', [['type' => 'paragraph', 'content' => []]]]]], $field);
        self::assertSame(['/rows/1/1/0/type'], array_keys($errors));

        $long = $type->validate(['rows' => [[[['type' => 'text', 'text' => str_repeat('a', 2001)]]]]], $field);
        self::assertSame(['/rows/0/0' => 'has more than 2000 characters.'], $long);
    }

    private function type(): FieldType
    {
        return $this->get(FieldTypes::class)->get('table');
    }

    /**
     * @param array<string, mixed> $value
     */
    private function html(array $value): string
    {
        $output = $this->type()->output($value, new Field('table', 'table', 'Table'));
        self::assertInstanceOf(Markup::class, $output);

        return (string) $output;
    }
}
