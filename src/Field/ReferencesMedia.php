<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Field;

/**
 * A field type whose values use media. Publishing a page makes them
 * public (decision 0126).
 */
interface ReferencesMedia extends FieldType
{
    /**
     * @return list<string> Ids of the media in a stored value
     */
    public function media(mixed $value, Field $field): array;
}
