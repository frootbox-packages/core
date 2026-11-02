<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Field;

/**
 * A field type whose values hold text to find, e.g. in the global search
 * of the CMS (decision 0214). Types without it add nothing.
 */
interface Searchable extends FieldType
{
    /**
     * The text of a stored value as plain text, lines apart.
     */
    public function searchText(mixed $value, Field $field): string;
}
