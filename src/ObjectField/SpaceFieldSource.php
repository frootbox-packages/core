<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\ObjectField;

/**
 * Gives the further fields of one space (decision 0250), registered with
 * SpaceFields.
 */
interface SpaceFieldSource
{
    /**
     * The fields of a space in their order, written like "object.fields"
     * in the header of a view, with "labels" by language for visitors:
     *
     *     ['altitude' => ['type' => 'number', 'label' => 'Höhe', 'labels' => ['de' => 'Höhe', 'en' => 'Altitude'], 'unit' => 'm']]
     *
     * @return array<string, array<string, mixed>>
     */
    public function fields(string $space): array;

    /**
     * Shown as the source of the fields, e.g. 'Collection "Farms"'.
     */
    public function name(string $space): string;
}
