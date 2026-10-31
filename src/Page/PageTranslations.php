<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Page;

use Frootbox\Database\Repository;

/**
 * @extends Repository<PageTranslation>
 */
final class PageTranslations extends Repository
{
    public const string TABLE = 'page_translations';

    /**
     * @return array<string, PageTranslation> by language
     */
    public function of(string $pageId): array
    {
        $translations = [];

        foreach ($this->fetch(['where' => ['pageId' => $pageId]]) as $translation) {
            $translations[$translation->language] = $translation;
        }

        return $translations;
    }

    protected function table(): string
    {
        return self::TABLE;
    }

    protected function entity(): string
    {
        return PageTranslation::class;
    }
}
