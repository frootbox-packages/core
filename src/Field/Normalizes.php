<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Field;

/**
 * A field type that completes a value before it is stored, e.g. a list
 * that gives each entry an id (decision 0344). It runs on every save of
 * a draft, so it must keep what is already there.
 */
interface Normalizes extends FieldType
{
    public function normalize(mixed $value, Field $field): mixed;
}
