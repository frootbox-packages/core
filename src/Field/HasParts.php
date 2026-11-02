<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Field;

use Twig\Markup;

/**
 * A field type whose value has parts a template places on their own,
 * e.g. the caption and copyright of an image (decision 0203):
 *
 *     <figcaption>{{ field('image', 'caption') }}</figcaption>
 *
 * An empty part leaves out its element for visitors like an empty field.
 */
interface HasParts extends FieldType
{
    /**
     * The parts this field offers with its settings, e.g. ["copyright"].
     *
     * @return list<string>
     */
    public function parts(Field $field): array;

    /**
     * One part ready for output, "" when empty.
     */
    public function part(mixed $value, Field $field, string $part): Markup|string;
}
