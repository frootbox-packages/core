<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Media;

use Frootbox\Core\Language\Languages;
use Frootbox\Core\Page\PageReader;
use Frootbox\Core\Plugin\Plugins;
use Frootbox\Core\Revision\Revisions;
use Frootbox\Core\Revision\RevisionStatus;
use Frootbox\Core\Site\SiteFields;

/**
 * Where objects of modules use media (decision 0287): every draft or
 * published revision that is no page, no fields of the website and no
 * draft of a medium, asked
 * when needed instead of kept in media_usages. Modules register nothing.
 */
final readonly class ObjectMedia
{
    /**
     * Pages and the fields of the website are in media_usages (decision
     * 0146); the drafts of media are no place that uses a medium.
     */
    private const array SKIPPED = [PageReader::TYPE, SiteFields::TYPE, MediaDrafts::TYPE];

    public function __construct(
        private Revisions $revisions,
        private Plugins $plugins,
        private Languages $languages,
    ) {}

    /**
     * The objects that use a medium, in their draft or published.
     *
     * @return list<MediumUsage>
     */
    public function usages(string $mediaId): array
    {
        $places = [];

        foreach ($this->revisions->current(self::SKIPPED, $mediaId) as $revision) {
            if (!\in_array($mediaId, MediaReferences::inValue($revision->data), true)) {
                continue;
            }

            $key = $revision->type . '|' . $revision->entityId;
            $places[$key] ??= ['type' => $revision->type, 'id' => $revision->entityId, 'draft' => false, 'published' => false];
            $places[$key][RevisionStatus::Published === $revision->status ? 'published' : 'draft'] = true;
        }

        return array_values(array_map(
            fn(array $place): MediumUsage => new MediumUsage($place['type'], $place['id'], '', $this->title($place['type'], $place['id']), $place['draft'], $place['published'], $this->kind($place['type'])),
            $places,
        ));
    }

    /**
     * How many published objects use a medium.
     */
    public function published(string $mediaId): int
    {
        return \count(array_filter($this->usages($mediaId), static fn(MediumUsage $usage): bool => $usage->published));
    }

    /**
     * Ids of all media that objects use, in a draft or published.
     *
     * @return list<string>
     */
    public function used(): array
    {
        $ids = [];

        foreach ($this->revisions->current(self::SKIPPED) as $revision) {
            foreach (MediaReferences::inValue($revision->data) as $id) {
                $ids[$id] = true;
            }
        }

        return array_keys($ids);
    }

    /**
     * The name of the kind for editors: that of the plugin, else the last
     * part of the type, e.g. "Location".
     */
    private function kind(string $type): string
    {
        $plugin = $this->plugins->get($type);

        if (null !== $plugin) {
            return $plugin->label();
        }

        $parts = explode('/', $type);

        return ucfirst(end($parts));
    }

    /**
     * The title in the first language that has one, as the plugin knows it.
     */
    private function title(string $type, string $id): string
    {
        $plugin = $this->plugins->get($type);

        if (null === $plugin) {
            return '';
        }

        foreach ($this->languages->codes() as $language) {
            $title = $plugin->title($id, $language);

            if (null !== $title && '' !== $title) {
                return $title;
            }
        }

        return '';
    }
}
