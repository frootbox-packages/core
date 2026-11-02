<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Site;

use Frootbox\Database\Repository;

/**
 * @extends Repository<Protection>
 */
final class Protections extends Repository
{
    public const string TABLE = 'site_protection';

    public function current(): ?Protection
    {
        return $this->fetchOne(['order' => 'id']);
    }

    protected function table(): string
    {
        return self::TABLE;
    }

    protected function entity(): string
    {
        return Protection::class;
    }
}
