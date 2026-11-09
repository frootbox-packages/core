<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Page;

use Doctrine\DBAL\Exception\TableNotFoundException;
use Frootbox\Database\Repository;

/**
 * @extends Repository<PageProtection>
 */
final class PageProtections extends Repository
{
    public const string TABLE = 'page_protections';

    public function of(string $pageId): ?PageProtection
    {
        return $this->fetchOne(['where' => ['page_id' => $pageId]]);
    }

    /**
     * Every protection by page. None before the table is migrated, so
     * pages are still delivered after an upload without migrate
     * (decision 0327).
     *
     * @return array<string, PageProtection>
     */
    public function all(): array
    {
        $protections = [];

        try {
            foreach ($this->fetch() as $protection) {
                $protections[$protection->pageId] = $protection;
            }
        } catch (TableNotFoundException) {
            return [];
        }

        return $protections;
    }

    protected function table(): string
    {
        return self::TABLE;
    }

    protected function entity(): string
    {
        return PageProtection::class;
    }
}
