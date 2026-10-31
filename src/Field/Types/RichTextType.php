<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Field\Types;

use Frootbox\Core\Field\AsFieldType;
use Frootbox\Core\Field\Field;
use Frootbox\Core\Field\FieldType;
use Frootbox\RichText\RichText;
use Twig\Markup;

/**
 * Formatted text from the editor (Tiptap JSON), output as safe HTML.
 */
#[AsFieldType('richtext', 'Formatted text with paragraphs, lists and links')]
final readonly class RichTextType implements FieldType
{
    public function __construct(private RichText $richText) {}

    public function settings(): array
    {
        return [];
    }

    public function valueSchema(Field $field): array
    {
        return ['type' => 'object', 'required' => ['type'], 'properties' => ['type' => ['const' => 'doc']]];
    }

    public function validate(mixed $value, Field $field): array
    {
        $errors = [];

        foreach ($this->richText->validate($value) as $violation) {
            $errors[$violation->pointer] = $violation->message;
        }

        return $errors;
    }

    public function output(mixed $value, Field $field): Markup
    {
        return new Markup($this->richText->toHtml($value), 'UTF-8');
    }
}
