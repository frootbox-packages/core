<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Plugin;

use Frootbox\Database\Repository;

/**
 * @extends Repository<Space>
 */
final class Spaces extends Repository
{
    public const string TABLE = 'spaces';

    /**
     * The spaces of a type with a row, by title.
     *
     * @return list<Space>
     */
    public function ofType(string $type): array
    {
        return $this->fetch(['where' => ['type' => $type], 'order' => 'title'])->all();
    }

    protected function table(): string
    {
        return self::TABLE;
    }

    protected function entity(): string
    {
        return Space::class;
    }
}
