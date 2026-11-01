<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Media;

use Frootbox\Core\Page\EditMode;
use Frootbox\Core\Revision\Revisions;

/**
 * The drafts of media (decision 0147). The tables media and media_texts
 * hold what visitors see; a public medium keeps changes to what visitors
 * see as a revision "media" until they are published:
 *
 *     {"version": 3, "focusX": 250, "focusY": 500,
 *      "texts": {"de": {"alt": "…", "title": "…"}}}
 *
 * The version is the one of the published medium, one more once the
 * sizes differ (a new focus point). Guests see the published state, the
 * editing mode and the media library the draft.
 */
final readonly class MediaDrafts
{
    public const string TYPE = 'media';

    public function __construct(
        private Revisions $revisions,
        private MediaTexts $texts,
        private EditMode $editMode,
    ) {}

    /**
     * @return array{version: int, focusX: int, focusY: int, texts: array<string, array{alt: string, title: string}>}|null
     */
    public function of(string $id): ?array
    {
        $data = $this->revisions->draft(self::TYPE, $id)?->data;

        return null === $data ? null : self::clean($data);
    }

    /**
     * Ids of the media with a draft.
     *
     * @return list<string>
     */
    public function ids(): array
    {
        return array_values(array_map(static fn($revision): string => $revision->entityId, $this->revisions->drafts(self::TYPE)));
    }

    /**
     * The published state as the start of a draft.
     *
     * @return array{version: int, focusX: int, focusY: int, texts: array<string, array{alt: string, title: string}>}
     */
    public function start(Medium $medium): array
    {
        return ['version' => $medium->version, 'focusX' => $medium->focusX, 'focusY' => $medium->focusY, 'texts' => $this->published($medium->id)];
    }

    /**
     * The medium with the values of its draft, if it has one.
     */
    public function current(Medium $medium): Medium
    {
        $draft = $this->of($medium->id);

        return null === $draft ? $medium : self::apply($medium, $draft);
    }

    /**
     * What a request sees: the draft in the editing mode, else the
     * published state.
     */
    public function seen(Medium $medium): Medium
    {
        return $this->editMode->active() ? $this->current($medium) : $medium;
    }

    /**
     * Alternative texts and titles by language, those of the draft with
     * $draft and one there.
     *
     * @return array<string, array{alt: string, title: string}>
     */
    public function texts(string $id, bool $draft): array
    {
        $found = $draft ? $this->of($id) : null;

        return null === $found ? $this->published($id) : $found['texts'];
    }

    /**
     * The texts for a request: those of the draft in the editing mode.
     *
     * @return array<string, array{alt: string, title: string}>
     */
    public function seenTexts(string $id): array
    {
        return $this->texts($id, $this->editMode->active());
    }

    /**
     * @param array{version: int, focusX: int, focusY: int, texts: array<string, array{alt: string, title: string}>} $draft
     */
    public static function apply(Medium $medium, array $draft): Medium
    {
        return new Medium(
            $medium->id,
            $medium->folderId,
            $medium->fileName,
            $medium->type,
            $medium->size,
            $medium->width,
            $medium->height,
            $draft['focusX'],
            $draft['focusY'],
            $draft['version'],
            $medium->public,
            $medium->hash,
            $medium->createdAt,
            $medium->updatedAt,
        );
    }

    /**
     * @param array<mixed> $data
     *
     * @return array{version: int, focusX: int, focusY: int, texts: array<string, array{alt: string, title: string}>}
     */
    private static function clean(array $data): array
    {
        $texts = [];

        foreach (\is_array($data['texts'] ?? null) ? $data['texts'] : [] as $language => $text) {
            if (\is_array($text)) {
                $texts[(string) $language] = [
                    'alt' => \is_string($text['alt'] ?? null) ? $text['alt'] : '',
                    'title' => \is_string($text['title'] ?? null) ? $text['title'] : '',
                ];
            }
        }

        return [
            'version' => \is_int($data['version'] ?? null) ? $data['version'] : 1,
            'focusX' => \is_int($data['focusX'] ?? null) ? $data['focusX'] : 500,
            'focusY' => \is_int($data['focusY'] ?? null) ? $data['focusY'] : 500,
            'texts' => $texts,
        ];
    }

    /**
     * @return array<string, array{alt: string, title: string}>
     */
    private function published(string $id): array
    {
        $texts = [];

        foreach ($this->texts->of($id) as $language => $text) {
            $texts[$language] = ['alt' => $text->alt, 'title' => $text->title];
        }

        return $texts;
    }
}
