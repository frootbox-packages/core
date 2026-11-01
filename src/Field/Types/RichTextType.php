<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Field\Types;

use Closure;
use Frootbox\Core\Field\AsFieldType;
use Frootbox\Core\Field\Field;
use Frootbox\Core\Field\FieldType;
use Frootbox\Core\Field\OutputsHeadings;
use Frootbox\RichText\Nodes\Heading;
use Frootbox\RichText\RichText;
use Twig\Markup;

/**
 * Formatted text from the editor (Tiptap JSON), output as safe HTML.
 *
 * Headings are stored with their rank: 1 „Überschrift“, 2
 * „Unterüberschrift“, 3 „Kleine Überschrift“ (decision 0129). On a page
 * the core decides their levels; elsewhere rank 1 is an h2. The setting
 * "headings" limits the ranks, 0 allows none.
 */
#[AsFieldType('richtext', 'Formatted text with paragraphs, lists and links')]
final readonly class RichTextType implements FieldType, OutputsHeadings
{
    public const int RANKS = 3;

    public function __construct(private RichText $richText) {}

    public function settings(): array
    {
        return ['headings' => ['type' => 'integer', 'minimum' => 0, 'maximum' => self::RANKS]];
    }

    public function valueSchema(Field $field): array
    {
        return ['type' => 'object', 'required' => ['type'], 'properties' => ['type' => ['const' => 'doc']]];
    }

    public function validate(mixed $value, Field $field): array
    {
        $errors = [];

        foreach ($this->richText($field)->validate($value) as $violation) {
            $errors[$violation->pointer] = $violation->message;
        }

        return $errors;
    }

    public function output(mixed $value, Field $field): Markup
    {
        return $this->outputWithHeadings($value, $field, static fn(int $rank): int => min(6, $rank + 1));
    }

    public function outputWithHeadings(mixed $value, Field $field, Closure $level): Markup
    {
        return new Markup($this->richText($field, $level)->toHtml($value), 'UTF-8');
    }

    public function headingRanks(Field $field): int
    {
        return max(0, min(self::RANKS, $field->intSetting('headings', self::RANKS) ?? self::RANKS));
    }

    /**
     * @param (Closure(int, string): int)|null $level
     */
    private function richText(Field $field, ?Closure $level = null): RichText
    {
        $ranks = $this->headingRanks($field);

        return 0 === $ranks
            ? new RichText($this->richText->schema->withoutNode('heading'))
            : $this->richText->withNode(new Heading(match ($ranks) {
                1 => [1],
                2 => [1, 2],
                default => [1, 2, 3],
            }, $level));
    }
}
