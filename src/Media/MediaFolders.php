<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Media;

use Frootbox\Database\Repository;

/**
 * @extends Repository<MediaFolder>
 */
final class MediaFolders extends Repository
{
    public const string TABLE = 'media_folders';

    protected function table(): string
    {
        return self::TABLE;
    }

    protected function entity(): string
    {
        return MediaFolder::class;
    }
}
