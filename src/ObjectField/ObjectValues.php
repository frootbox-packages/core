<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\ObjectField;

use Frootbox\Database\Repository;

/**
 * The further fields of the published objects (decision 0240), written
 * by ObjectFields.
 *
 * @extends Repository<ObjectValue>
 */
final class ObjectValues extends Repository
{
    public const string TABLE = 'object_fields';

    protected function table(): string
    {
        return self::TABLE;
    }

    protected function entity(): string
    {
        return ObjectValue::class;
    }
}
