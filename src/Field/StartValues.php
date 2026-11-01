<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Field;

use stdClass;

/**
 * What a new block begins with (decision 0142): each list field gets as
 * many empty entries as its "start" says, else as its "min" asks, at most
 * its "max". Empty fields show their placeholder while editing, so the
 * block looks like itself right away and no sample text goes live.
 */
final class StartValues
{
    /**
     * @param array<string, Field> $fields
     *
     * @return array<string, list<array<string, mixed>|stdClass>> Values by field name, only for lists with entries
     */
    public static function of(array $fields): array
    {
        $values = [];

        foreach ($fields as $name => $field) {
            if ([] === $field->fields) {
                continue;
            }

            $count = $field->intSetting('start') ?? $field->intSetting('min') ?? 0;
            $count = max($count, $field->intSetting('min') ?? 0);
            $count = min($count, $field->intSetting('max') ?? $count);

            if ($count > 0) {
                // An entry without values stays an object in JSON.
                $entry = self::of($field->fields);
                $values[$name] = array_fill(0, $count, [] === $entry ? new stdClass() : $entry);
            }
        }

        return $values;
    }
}
