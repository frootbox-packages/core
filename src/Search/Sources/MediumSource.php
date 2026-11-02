<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Search\Sources;

use Frootbox\Core\Media\Media;
use Frootbox\Core\Media\MediaDrafts;
use Frootbox\Core\Media\MediaTags;
use Frootbox\Core\Media\MediaTexts;
use Frootbox\Core\Revision\Revisions;
use Frootbox\Core\Search\AsSearchSource;
use Frootbox\Core\Search\SearchEntry;
use Frootbox\Core\Search\SearchSource;

/**
 * Media of the library: file name, descriptions and titles in every
 * language, the draft first, and tags (decision 0214).
 */
#[AsSearchSource('medium', 'Media')]
final readonly class MediumSource implements SearchSource
{
    public function __construct(
        private Media $media,
        private MediaDrafts $drafts,
        private MediaTags $tags,
    ) {}

    public function changed(string $table, array $row): array
    {
        $id = match ($table) {
            Media::TABLE => $row['id'] ?? null,
            MediaTexts::TABLE, MediaTags::TABLE => $row['media_id'] ?? null,
            Revisions::TABLE => MediaDrafts::TYPE === ($row['type'] ?? null) ? $row['entity_id'] ?? null : null,
            default => null,
        };

        return \is_string($id) ? [$id] : [];
    }

    public function items(): iterable
    {
        foreach ($this->media->fetch(['order' => 'id']) as $medium) {
            yield $medium->id;
        }
    }

    public function entries(array $items): iterable
    {
        foreach ($items as $id) {
            $medium = $this->media->fetchById($id);

            if (null === $medium) {
                continue;
            }

            $medium = $this->drafts->current($medium);
            $texts = [];

            foreach ($this->drafts->texts($id, true) as $text) {
                array_push($texts, $text['title'], $text['alt']);
            }

            yield new SearchEntry(
                item: $id,
                title: $medium->fileName,
                text: implode("\n", array_unique(array_filter([...$texts, ...$this->tags->of($id)], static fn(string $text): bool => '' !== trim($text)))),
                permission: 'media.list',
            );
        }
    }
}
