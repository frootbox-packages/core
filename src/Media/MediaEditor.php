<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Media;

use Frootbox\Core\Action\InvalidInputException;
use Frootbox\Core\Language\Languages;
use Frootbox\Core\Support\Message;
use Frootbox\Database\EntityNotFoundException;
use Frootbox\Database\Ulid;

/**
 * What the media actions do (decision 0126). Texts, tags, folder and
 * focus point have no draft; a new focus point gives a new version, so
 * the sizes are generated anew.
 */
final readonly class MediaEditor
{
    public const int MAX_TAGS = 30;

    public function __construct(
        private Media $media,
        private MediaTexts $texts,
        private MediaTags $tags,
        private MediaFolders $folders,
        private Uploads $uploads,
        private Images $images,
        private MediaFiles $files,
        private MediaUrls $urls,
        private Languages $languages,
    ) {}

    /**
     * @param array<mixed> $texts
     * @param list<string> $tags
     *
     * @throws InvalidInputException
     */
    public function create(string $action, string $upload, ?string $folderId, array $texts, array $tags): MediumDetails
    {
        $path = $this->uploads->path($upload);
        $errors = null === $path ? ['/upload' => 'There is no upload with this token (any more). Upload the file first.'] : [];
        $errors += $this->checkFolder($folderId, '/folderId');
        [$texts, $textErrors] = $this->cleanTexts($texts);
        [$tags, $tagErrors] = self::cleanTags($tags);
        $errors += $textErrors + $tagErrors;

        if ([] !== $errors || null === $path) {
            throw new InvalidInputException($action, $errors);
        }

        $hash = $this->uploads->hash($upload);
        $same = null === $hash ? null : $this->media->fetchOne(['where' => ['hash' => $hash]]);

        // The same file is already in the library: no second copy (decision 0146).
        if (null !== $same) {
            $this->uploads->remove($upload);

            return $this->details($same, existing: true);
        }

        $id = Ulid::generate();
        $facts = $this->images->prepare($path, $this->files->original($id));

        if ($facts instanceof Message) {
            throw new InvalidInputException($action, ['/upload' => $facts]);
        }

        $medium = $this->media->insert([
            'id' => $id,
            'folderId' => $folderId,
            'fileName' => $this->uploads->name($upload),
            'type' => $facts['type'],
            'size' => $facts['size'],
            'width' => $facts['width'],
            'height' => $facts['height'],
            'focusX' => 500,
            'focusY' => 500,
            'version' => 1,
            'public' => false,
            'hash' => $hash,
        ]);

        $this->saveTexts($id, $texts);
        $this->saveTags($id, $tags);
        $this->uploads->remove($upload);

        return $this->details($medium);
    }

    /**
     * @param string|null       $folderId "" for the top, null to keep it
     * @param array<mixed>|null $texts    Languages given are replaced
     * @param list<string>|null $tags     Replace all tags
     *
     * @throws InvalidInputException
     * @throws EntityNotFoundException
     */
    public function update(string $action, string $id, ?string $folderId, ?float $focusX, ?float $focusY, ?array $texts, ?array $tags): MediumDetails
    {
        $medium = $this->media->getById($id);
        $errors = null === $folderId || '' === $folderId ? [] : $this->checkFolder($folderId, '/folderId');
        [$cleanTexts, $textErrors] = null === $texts ? [[], []] : $this->cleanTexts($texts);
        [$cleanTags, $tagErrors] = null === $tags ? [[], []] : self::cleanTags($tags);
        $errors += $textErrors + $tagErrors;

        if ((null === $focusX) !== (null === $focusY)) {
            $errors['/focusX'] = 'needs focusY as well, both as shares from 0 to 1.';
        }

        if ([] !== $errors) {
            throw new InvalidInputException($action, $errors);
        }

        $values = [];

        if (null !== $folderId) {
            $values['folderId'] = '' === $folderId ? null : $folderId;
        }

        if (null !== $focusX && null !== $focusY) {
            $x = (int) round($focusX * 1000);
            $y = (int) round($focusY * 1000);

            if ($x !== $medium->focusX || $y !== $medium->focusY) {
                $values += ['focusX' => $x, 'focusY' => $y, 'version' => $medium->version + 1];
                $this->files->removePublic($id);
            }
        }

        if ([] !== $values) {
            $medium = $this->media->update($id, $values);
        }

        if (null !== $texts) {
            $this->saveTexts($id, $cleanTexts);
        }

        if (null !== $tags) {
            $this->saveTags($id, $cleanTags);
        }

        return $this->details($medium);
    }

    /**
     * Removes the medium and its generated files. The original stays until
     * media:prune, so undo can bring the medium back.
     *
     * @throws EntityNotFoundException
     */
    public function delete(string $id): void
    {
        $this->media->getById($id);

        foreach ($this->texts->fetch(['where' => ['mediaId' => $id]]) as $text) {
            $this->texts->delete($text->id);
        }

        foreach ($this->tags->fetch(['where' => ['mediaId' => $id]]) as $tag) {
            $this->tags->delete($tag->id);
        }

        $this->media->delete($id);
        $this->files->removePublic($id);

        // The original stays for undo; media:prune counts its age from now.
        @touch($this->files->original($id));
    }

    /**
     * Makes media public, e.g. those a page uses that is published now.
     *
     * @param list<string> $ids Unknown ids are left out
     */
    public function publish(array $ids): void
    {
        if ([] === $ids) {
            return;
        }

        foreach ($this->media->fetch(['where' => ['id' => array_values(array_unique($ids)), 'public' => false]]) as $medium) {
            $this->media->update($medium->id, ['public' => true]);
        }
    }

    /**
     * @throws InvalidInputException
     */
    public function createFolder(string $action, string $name, ?string $parentId): MediaFolder
    {
        $errors = $this->checkFolder($parentId, '/parentId') + self::checkName($name);

        if ([] !== $errors) {
            throw new InvalidInputException($action, $errors);
        }

        return $this->folders->insert(['name' => trim($name), 'parentId' => $parentId]);
    }

    /**
     * @param string|null $parentId "" for the top, null to keep it
     *
     * @throws InvalidInputException
     * @throws EntityNotFoundException
     */
    public function updateFolder(string $action, string $id, ?string $name, ?string $parentId): MediaFolder
    {
        $folder = $this->folders->getById($id);
        $errors = null === $name ? [] : self::checkName($name);

        if (null !== $parentId && '' !== $parentId) {
            $errors += $this->checkFolder($parentId, '/parentId');

            if (\in_array($id, $this->ancestorsAndSelf($parentId), true)) {
                $errors['/parentId'] = 'A folder cannot go into itself or a folder inside it.';
            }
        }

        if ([] !== $errors) {
            throw new InvalidInputException($action, $errors);
        }

        $values = [];

        if (null !== $name) {
            $values['name'] = trim($name);
        }

        if (null !== $parentId) {
            $values['parentId'] = '' === $parentId ? null : $parentId;
        }

        return [] === $values ? $folder : $this->folders->update($id, $values);
    }

    /**
     * Only an empty folder can go.
     *
     * @throws InvalidInputException
     * @throws EntityNotFoundException
     */
    public function deleteFolder(string $action, string $id): void
    {
        $this->folders->getById($id);

        if ($this->media->count(['folderId' => $id]) > 0 || $this->folders->count(['parentId' => $id]) > 0) {
            throw new InvalidInputException($action, ['/id' => 'The folder is not empty. Move or delete what is in it first.']);
        }

        $this->folders->delete($id);
    }

    public function details(Medium $medium, bool $existing = false): MediumDetails
    {
        $texts = [];

        foreach ($this->texts->of($medium->id) as $language => $text) {
            $texts[$language] = ['alt' => $text->alt, 'title' => $text->title];
        }

        $formats = $this->urls->formats($medium);
        $preview = \in_array('webp', $formats, true) ? 'webp' : $formats[\count($formats) - 1];

        return new MediumDetails(
            $medium->id,
            $medium->folderId,
            $medium->fileName,
            $medium->type,
            $medium->size,
            $medium->width,
            $medium->height,
            ['x' => round($medium->focusX / 1000, 3), 'y' => round($medium->focusY / 1000, 3)],
            $medium->version,
            $medium->public,
            $texts,
            $this->tags->of($medium->id),
            $this->urls->url($medium, min(480, $medium->width), 0, $preview),
            $medium->createdAt,
            $existing,
        );
    }

    /**
     * @return array<string, string>
     */
    private function checkFolder(?string $folderId, string $pointer): array
    {
        return null === $folderId || null !== $this->folders->fetchById($folderId) ? [] : [$pointer => 'There is no folder with this id.'];
    }

    /**
     * @return list<string> The folder and those above it
     */
    private function ancestorsAndSelf(string $id): array
    {
        $ids = [];

        while (null !== $id && !\in_array($id, $ids, true)) {
            $ids[] = $id;
            $id = $this->folders->fetchById($id)?->parentId;
        }

        return $ids;
    }

    /**
     * @return array<string, string>
     */
    private static function checkName(string $name): array
    {
        $length = mb_strlen(trim($name));

        return $length < 1 || $length > 200 ? ['/name' => 'needs 1 to 200 characters.'] : [];
    }

    /**
     * @param array<mixed> $texts {"de": {"alt": "…", "title": "…"}}
     *
     * @return array{array<string, array{alt?: string, title?: string}>, array<string, string|Message>}
     */
    private function cleanTexts(array $texts): array
    {
        $clean = [];
        $errors = [];

        foreach ($texts as $language => $text) {
            $language = (string) $language;
            $at = '/texts/' . $language;

            if (!$this->languages->has($language)) {
                $errors[$at] = new Message('"{language}" is not a language of the website.', ['language' => $language]);

                continue;
            }

            if (!\is_array($text) || [] !== array_diff(array_keys($text), ['alt', 'title'])) {
                $errors[$at] = 'must be an object with "alt" and "title".';

                continue;
            }

            foreach (['alt' => 500, 'title' => 200] as $key => $max) {
                if (!\array_key_exists($key, $text)) {
                    continue;
                }

                $value = $text[$key];

                if (!\is_string($value) || mb_strlen($value) > $max) {
                    $errors[$at . '/' . $key] = new Message('must be text of at most {max} characters.', ['max' => $max]);
                } else {
                    $clean[$language][$key] = trim($value);
                }
            }
        }

        return [$clean, $errors];
    }

    /**
     * @param array<mixed> $tags
     *
     * @return array{list<string>, array<string, string|Message>}
     */
    private static function cleanTags(array $tags): array
    {
        $clean = [];

        foreach ($tags as $index => $tag) {
            if (!\is_string($tag) || '' === trim($tag) || mb_strlen(trim($tag)) > 100) {
                return [[], ['/tags/' . $index => 'must be text of 1 to 100 characters.']];
            }

            $clean[mb_strtolower(trim($tag))] = trim($tag);
        }

        if (\count($clean) > self::MAX_TAGS) {
            return [[], ['/tags' => new Message('has at most {max} tags.', ['max' => self::MAX_TAGS])]];
        }

        return [array_values($clean), []];
    }

    /**
     * @param array<string, array{alt?: string, title?: string}> $texts
     */
    private function saveTexts(string $id, array $texts): void
    {
        $existing = $this->texts->of($id);

        foreach ($texts as $language => $text) {
            $values = ['alt' => $text['alt'] ?? $existing[$language]->alt ?? '', 'title' => $text['title'] ?? $existing[$language]->title ?? ''];

            if (isset($existing[$language])) {
                $this->texts->update($existing[$language]->id, $values);
            } else {
                $this->texts->insert(['mediaId' => $id, 'language' => $language, ...$values]);
            }
        }
    }

    /**
     * @param list<string> $tags
     */
    private function saveTags(string $id, array $tags): void
    {
        $existing = [];

        foreach ($this->tags->fetch(['where' => ['mediaId' => $id]]) as $tag) {
            if (\in_array($tag->tag, $tags, true)) {
                $existing[] = $tag->tag;
            } else {
                $this->tags->delete($tag->id);
            }
        }

        foreach (array_diff($tags, $existing) as $tag) {
            $this->tags->insert(['mediaId' => $id, 'tag' => $tag]);
        }
    }
}
