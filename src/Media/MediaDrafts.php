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
 *      "texts": {"de": {"alt": "…", "title": "…"}},
 *      "file": {"name": "01J…-a1b2c3d4", "fileName": "kaffee.jpg",
 *               "type": "image/jpeg", "size": 81234, "width": 1600,
 *               "height": 900, "hash": "…"}}
 *
 * "file" is null as long as the file stays; a new one (media.replace)
 * keeps its own original. The version is the one of the published
 * medium, one more once the sizes differ (a new file or focus point).
 * Guests see the published state, the editing mode and the media
 * library the draft.
 *
 * @phpstan-type DraftFile array{name: string, fileName: string, type: string, codec: string, size: int, width: int, height: int, hash: string|null}
 * @phpstan-type Draft array{version: int, focusX: int, focusY: int, texts: array<string, array{alt: string, title: string}>, file: DraftFile|null}
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
     * @return Draft|null
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
     * @return Draft
     */
    public function start(Medium $medium): array
    {
        return ['version' => $medium->version, 'focusX' => $medium->focusX, 'focusY' => $medium->focusY, 'texts' => $this->published($medium->id), 'file' => null];
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
     * @param Draft $draft
     */
    public static function apply(Medium $medium, array $draft): Medium
    {
        $file = $draft['file'];

        return new Medium(
            id: $medium->id,
            folderId: $medium->folderId,
            fileName: $file['fileName'] ?? $medium->fileName,
            type: $file['type'] ?? $medium->type,
            size: $file['size'] ?? $medium->size,
            width: $file['width'] ?? $medium->width,
            height: $file['height'] ?? $medium->height,
            focusX: $draft['focusX'],
            focusY: $draft['focusY'],
            version: $draft['version'],
            public: $medium->public,
            hash: null === $file ? $medium->hash : $file['hash'],
            createdAt: $medium->createdAt,
            updatedAt: $medium->updatedAt,
            file: null === $file ? $medium->file : $file['name'],
            codec: null === $file ? $medium->codec : $file['codec'],
        );
    }

    /**
     * The medium whose draft has a file with this hash.
     */
    public function idWithHash(string $hash): ?string
    {
        foreach ($this->revisions->drafts(self::TYPE) as $revision) {
            if ($hash === (self::clean($revision->data)['file']['hash'] ?? null)) {
                return $revision->entityId;
            }
        }

        return null;
    }

    /**
     * Names of the originals that drafts use.
     *
     * @return list<string>
     */
    public function originals(): array
    {
        $names = [];

        foreach ($this->revisions->drafts(self::TYPE) as $revision) {
            $file = self::clean($revision->data)['file'];

            if (null !== $file) {
                $names[] = $file['name'];
            }
        }

        return $names;
    }

    /**
     * @param array<mixed> $data
     *
     * @return Draft
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

        $file = $data['file'] ?? null;
        $int = static fn(mixed $value): int => \is_int($value) ? $value : 0;
        $text = static fn(mixed $value): string => \is_string($value) ? $value : '';

        return [
            'version' => \is_int($data['version'] ?? null) ? $data['version'] : 1,
            'focusX' => \is_int($data['focusX'] ?? null) ? $data['focusX'] : 500,
            'focusY' => \is_int($data['focusY'] ?? null) ? $data['focusY'] : 500,
            'texts' => $texts,
            'file' => !\is_array($file) || !\is_string($file['name'] ?? null) ? null : [
                'name' => $file['name'],
                'fileName' => $text($file['fileName'] ?? null),
                'type' => $text($file['type'] ?? null),
                'codec' => $text($file['codec'] ?? null),
                'size' => $int($file['size'] ?? null),
                'width' => $int($file['width'] ?? null),
                'height' => $int($file['height'] ?? null),
                'hash' => \is_string($file['hash'] ?? null) ? $file['hash'] : null,
            ],
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
