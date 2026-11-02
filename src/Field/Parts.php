<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Field;

use Frootbox\RichText\Html;
use Twig\Markup;

/**
 * The parts of a field as HTML, e.g. for the editing mode after saving
 * (decision 0203).
 */
final class Parts
{
    /**
     * @return array<string, string> By name of the part, empty for types without parts
     */
    public static function html(FieldType $type, mixed $value, Field $field): array
    {
        if (!$type instanceof HasParts) {
            return [];
        }

        $parts = [];

        foreach ($type->parts($field) as $part) {
            $output = $type->part($value, $field, $part);
            $parts[$part] = $output instanceof Markup ? (string) $output : Html::escape($output);
        }

        return $parts;
    }
}
