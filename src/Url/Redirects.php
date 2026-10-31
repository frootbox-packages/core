<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Url;

use Frootbox\Database\Repository;

/**
 * @extends Repository<Redirect>
 */
final class Redirects extends Repository
{
    public const string TABLE = 'redirects';

    public function at(string $path): ?Redirect
    {
        return $this->fetchOne(['where' => ['path' => $path]]);
    }

    protected function table(): string
    {
        return self::TABLE;
    }

    protected function entity(): string
    {
        return Redirect::class;
    }
}
