<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Field\Types;

use Frootbox\Core\Field\AsFieldType;
use Frootbox\Core\Field\Field;
use Frootbox\Core\Field\FieldType;
use Frootbox\RichText\Html;
use Frootbox\RichText\Marks\TagMark;
use Frootbox\RichText\Nodes\Line;
use Frootbox\RichText\Nodes\TagNode;
use Frootbox\RichText\RichText;
use Frootbox\RichText\Schema;
use Twig\Markup;

/**
 * A table (decision 0187):
 *
 *     {"rows": [["Tag", "Öffnungszeit"], ["Montag", "8–18 Uhr"]],
 *      "headerRow": true, "headerColumn": false}
 *
 * A cell is text or a list of inline nodes as in Tiptap: text with the
 * marks bold, italic and link, and hardBreak. "headerRow" (true when not
 * given) makes the first row the heads of the columns, "headerColumn"
 * the first cell of each row the head of its row. Shorter rows are
 * filled up with empty cells; cells are never merged.
 *
 * {{ field('table') }} gives a <table> without classes; the theme puts
 * it into an element that scrolls sideways when it is too wide.
 */
#[AsFieldType('table', 'A table with rows and columns, e.g. opening hours or prices')]
final readonly class TableType implements FieldType
{
    public const int MAX_COLUMNS = 20;

    public const int MAX_ROWS = 500;

    public const int MAX_CELL = 2000;

    private RichText $cells;

    public function __construct(RichText $richText)
    {
        $link = $richText->schema->mark('link');

        $this->cells = new RichText(new Schema(
            [new Line(), TagNode::hardBreak()],
            [...null === $link ? [] : [$link], new TagMark('bold', 'strong'), new TagMark('italic', 'em')],
        ));
    }

    public function settings(): array
    {
        return [];
    }

    public function valueSchema(Field $field): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['rows'],
            'properties' => [
                'rows' => [
                    'type' => 'array',
                    'maxItems' => self::MAX_ROWS,
                    'items' => [
                        'type' => 'array',
                        'maxItems' => self::MAX_COLUMNS,
                        'items' => [
                            'anyOf' => [
                                ['type' => 'string', 'maxLength' => self::MAX_CELL],
                                ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['type']]],
                            ],
                        ],
                    ],
                ],
                'headerRow' => ['type' => 'boolean'],
                'headerColumn' => ['type' => 'boolean'],
            ],
        ];
    }

    public function validate(mixed $value, Field $field): array
    {
        $errors = [];

        foreach (self::rows($value) as $row => $cells) {
            foreach ($cells as $column => $cell) {
                if (!\is_array($cell)) {
                    continue;
                }

                $pointer = '/rows/' . $row . '/' . $column;

                foreach ($this->cells->validate(self::document($cell)) as $violation) {
                    // The document wraps the cell in content/0/content.
                    $errors[$pointer . (string) preg_replace('#^/content/0/content#', '', $violation->pointer)] = $violation->message;
                }

                if (mb_strlen($this->cells->toText(self::document($cell))) > self::MAX_CELL) {
                    $errors[$pointer] = \sprintf('has more than %d characters.', self::MAX_CELL);
                }
            }
        }

        return $errors;
    }

    public function output(mixed $value, Field $field): Markup|string
    {
        $rows = self::rows($value);
        $width = max([0, ...array_map(\count(...), $rows)]);

        if (0 === $width) {
            return '';
        }

        $headerRow = !\is_array($value) || false !== ($value['headerRow'] ?? true);
        $headerColumn = \is_array($value) && true === ($value['headerColumn'] ?? false);
        $head = '';
        $body = '';

        foreach ($rows as $index => $cells) {
            $inHead = $headerRow && 0 === $index;
            $html = '';

            for ($column = 0; $column < $width; ++$column) {
                $content = $this->cell($cells[$column] ?? '');

                if ($inHead) {
                    $html .= Html::element('th', $content, ['scope' => 'col']);
                } elseif ($headerColumn && 0 === $column) {
                    $html .= Html::element('th', $content, ['scope' => 'row']);
                } else {
                    $html .= Html::element('td', $content);
                }
            }

            if ($inHead) {
                $head = Html::element('thead', Html::element('tr', $html));
            } else {
                $body .= Html::element('tr', $html);
            }
        }

        return new Markup(Html::element('table', $head . ('' === $body ? '' : Html::element('tbody', $body))), 'UTF-8');
    }

    /**
     * The rows of a value, each a list of cells.
     *
     * @return list<list<mixed>>
     */
    private static function rows(mixed $value): array
    {
        $rows = \is_array($value) && \is_array($value['rows'] ?? null) ? $value['rows'] : [];

        return array_values(array_map(
            static fn(mixed $row): array => \is_array($row) ? array_values($row) : [],
            $rows,
        ));
    }

    private function cell(mixed $cell): string
    {
        return match (true) {
            \is_string($cell) => str_replace("\n", '<br>', Html::escape($cell)),
            \is_array($cell) => $this->cells->toHtml(self::document($cell)),
            default => '',
        };
    }

    /**
     * @param array<mixed> $cell
     *
     * @return array<string, mixed>
     */
    private static function document(array $cell): array
    {
        return ['type' => 'doc', 'content' => [['type' => Line::NAME, 'content' => $cell]]];
    }
}
