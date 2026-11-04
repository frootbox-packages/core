<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Media;

use Frootbox\Core\Page\PageReader;
use Frootbox\Core\Url\Target;
use Frootbox\Core\Url\Urls;

/**
 * The image credits of the website (decision 0258): every public medium
 * with a copyright that published pages or the published fields of the
 * website show, from the index of usages (decision 0146). For the imprint,
 * where Frootbox 1 had CopyrightFilesList.
 */
final readonly class Credits
{
    /** Width of the small image of a line. */
    public const int PREVIEW_WIDTH = 160;

    public function __construct(
        private MediaUsages $usages,
        private Media $media,
        private MediaUrls $urls,
        private PageReader $pages,
        private Urls $links,
    ) {}

    /**
     * @param string $contentLanguage The language of the content, also in the fallback
     * @param string $language        The language of the links to the pages
     *
     * @return list<Credit> By copyright, then file name
     */
    public function list(string $contentLanguage, string $language): array
    {
        $places = [];

        foreach ($this->usages->fetch(['where' => ['published' => true, 'language' => $contentLanguage]]) as $usage) {
            $places[$usage->mediaId][] = $usage;
        }

        if ([] === $places) {
            return [];
        }

        $credits = [];
        $titles = [];

        foreach ($this->media->fetch(['where' => ['id' => array_map(strval(...), array_keys($places)), 'public' => true]]) as $medium) {
            $copyright = trim($medium->copyright);

            if ('' === $copyright) {
                continue;
            }

            $everywhere = false;
            $pages = [];

            foreach ($places[$medium->id] as $usage) {
                if (UsageIndex::SITE === $usage->ownerType) {
                    $everywhere = true;
                } elseif (UsageIndex::PAGE === $usage->ownerType) {
                    $titles[$usage->ownerId] ??= $this->pages->published($usage->ownerId)?->translations[$contentLanguage]->title ?? '';
                    $url = $this->links->href(Target::page($usage->ownerId), $language);

                    if (null !== $url && '' !== $titles[$usage->ownerId]) {
                        $pages[$usage->ownerId] = new CreditPage($usage->ownerId, $titles[$usage->ownerId], $url);
                    }
                }
            }

            if (!$everywhere && [] === $pages) {
                continue;
            }

            $preview = $this->preview($medium);
            $height = null === $preview || $medium->width < 1 ? 0 : (int) round(self::PREVIEW_WIDTH * $medium->height / $medium->width);
            $credits[] = new Credit($medium->id, $copyright, $medium->fileName, $preview, null === $preview ? 0 : self::PREVIEW_WIDTH, $height, $everywhere, array_values($pages));
        }

        usort($credits, static fn(Credit $a, Credit $b): int => [mb_strtolower($a->copyright), $a->fileName] <=> [mb_strtolower($b->copyright), $b->fileName]);

        return $credits;
    }

    private function preview(Medium $medium): ?string
    {
        if (!$medium->isImage()) {
            return null;
        }

        if ($medium->isVector()) {
            return $this->urls->file($medium);
        }

        // The format every browser shows, the last one.
        return $this->urls->url($medium, self::PREVIEW_WIDTH, 0, array_last($this->urls->formats($medium)) ?? 'jpg');
    }
}
