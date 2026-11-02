<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Media;

use Frootbox\Database\Repository;

/**
 * The index of where media are used (decision 0146). It can be built
 * anew from the content at any time, see UsageIndex.
 *
 * @extends Repository<MediaUsage>
 */
final class MediaUsages extends Repository
{
    public const string TABLE = 'media_usages';

    /**
     * @return list<MediaUsage>
     */
    public function ofOwner(string $type, string $id): array
    {
        return $this->fetch(['where' => ['ownerType' => $type, 'ownerId' => $id]])->all();
    }

    /**
     * @return list<MediaUsage>
     */
    public function ofMedium(string $mediaId): array
    {
        return $this->fetch(['where' => ['mediaId' => $mediaId], 'order' => ['ownerType' => 'asc', 'ownerId' => 'asc', 'language' => 'asc']])->all();
    }

    protected function table(): string
    {
        return self::TABLE;
    }

    protected function entity(): string
    {
        return MediaUsage::class;
    }
}
