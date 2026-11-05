<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Media;

use Frootbox\Core\Action\ConflictException;
use Frootbox\Core\Action\InvalidInputException;
use Frootbox\Core\Language\Languages;
use Frootbox\Core\Revision\RevisionStore;
use Frootbox\Core\Support\Message;
use Frootbox\Database\EntityNotFoundException;
use Frootbox\Database\Ulid;

/**
 * What the media actions do (decision 0126). A public medium keeps
 * changes to file, texts and focus point in a draft until they are
 * published (decision 0147); folder and tags count at once. A new file
 * or focus point gives a new version, so the sizes are generated anew.
 *
 * @phpstan-import-type Draft from MediaDrafts
 * @phpstan-import-type DraftFile from MediaDrafts
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
        private MediaDrafts $drafts,
        private RevisionStore $store,
        private MediaUsages $usages,
        private Videos $videos,
        private MediaSources $sources,
        private Documents $documents,
        private ObjectMedia $objects,
    ) {}

    /**
     * @param array<mixed> $texts
     * @param list<string> $tags
     * @param string|null  $copyright Null takes the one from the file, if it has one (decision 0203)
     * @param AiOrigin|null $ai       Null takes the one from the file (decision 0251)
     *
     * @throws InvalidInputException
     */
    public function create(string $action, string $upload, ?string $folderId, array $texts, array $tags, ?string $copyright = null, ?AiOrigin $ai = null): MediumDetails
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
        $same = null === $hash ? null : $this->withHash($hash);

        // The same file is already in the library: no second copy (decision 0146).
        if (null !== $same) {
            $this->uploads->remove($upload);

            return $this->details($same, existing: true);
        }

        $id = Ulid::generate();
        // Read before the metadata go with preparing the original.
        $copyright = self::cleanCopyright($copyright ?? Images::copyright($path));
        $ai ??= Images::aiOrigin($path);
        $facts = $this->prepare($path, $this->files->target($id), null, $this->uploads->name($upload));

        if ($facts instanceof Message) {
            $this->files->drop($id);

            throw new InvalidInputException($action, ['/upload' => $facts]);
        }

        $this->files->keep($id, $facts['type'], self::keepLocal($facts['type']));

        $medium = $this->media->insert([
            'id' => $id,
            'folderId' => $folderId,
            'fileName' => $this->uploads->name($upload),
            'type' => $facts['type'],
            'codec' => $facts['codec'],
            'size' => $facts['size'],
            'width' => $facts['width'],
            'height' => $facts['height'],
            'focusX' => 500,
            'focusY' => 500,
            'version' => 1,
            'public' => false,
            'hash' => $hash,
            'copyright' => $copyright,
            'ai' => $ai->value,
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
     * @param string|null       $copyright Null to keep it (decision 0203)
     * @param AiOrigin|null     $ai        Null to keep it (decision 0251)
     *
     * @throws InvalidInputException
     * @throws EntityNotFoundException
     */
    public function update(string $action, string $id, ?string $folderId, ?float $focusX, ?float $focusY, ?array $texts, ?array $tags, ?string $copyright = null, ?AiOrigin $ai = null): MediumDetails
    {
        $medium = $this->media->getById($id);
        $errors = null === $folderId || '' === $folderId ? [] : $this->checkFolder($folderId, '/folderId');
        [$cleanTexts, $textErrors] = null === $texts ? [[], []] : $this->cleanTexts($texts);
        [$cleanTags, $tagErrors] = null === $tags ? [[], []] : self::cleanTags($tags);
        $errors += $textErrors + $tagErrors;

        if ((null === $focusX) !== (null === $focusY)) {
            $errors['/focusX'] = 'needs focusY as well, both as shares from 0 to 1.';
        } elseif (null !== $focusX && $medium->isVideo()) {
            $errors['/focusX'] = 'A video has no focus point.';
        } elseif (null !== $focusX && $medium->isDocument()) {
            $errors['/focusX'] = 'A document has no focus point.';
        }

        if ([] !== $errors) {
            throw new InvalidInputException($action, $errors);
        }

        $focus = null === $focusX || null === $focusY ? null : [(int) round($focusX * 1000), (int) round($focusY * 1000)];

        // Folder and tags no visitor sees: they count at once.
        if (null !== $folderId) {
            $medium = $this->media->update($id, ['folderId' => '' === $folderId ? null : $folderId]);
        }

        if (null !== $tags) {
            $this->saveTags($id, $cleanTags);
        }

        $draft = $this->drafts->of($id);

        if ($medium->public || null !== $draft) {
            $this->saveDraft($medium, $draft ?? $this->drafts->start($medium), $focus, null === $texts ? [] : $cleanTexts, null === $copyright ? null : self::cleanCopyright($copyright), $ai);

            return $this->details($medium);
        }

        // Nobody sees a medium that is not public yet: no draft needed.
        if (null !== $focus && ($focus[0] !== $medium->focusX || $focus[1] !== $medium->focusY)) {
            $medium = $this->media->update($id, ['focusX' => $focus[0], 'focusY' => $focus[1], 'version' => $medium->version + 1]);
            $this->files->removePublic($id);
        }

        if (null !== $texts) {
            $this->saveTexts($id, $cleanTexts);
        }

        if (null !== $copyright && self::cleanCopyright($copyright) !== $medium->copyright) {
            $medium = $this->media->update($id, ['copyright' => self::cleanCopyright($copyright)]);
        }

        if (null !== $ai && $ai->value !== $medium->ai) {
            $medium = $this->media->update($id, ['ai' => $ai->value]);
        }

        return $this->details($medium);
    }

    /**
     * Gives the medium a new file (decisions 0146, 0147): same id, new
     * original, the focus point stays. A public medium gets it in its
     * draft. Uploading the published file again takes a new file out of
     * the draft.
     *
     * @throws InvalidInputException
     * @throws EntityNotFoundException
     */
    public function replace(string $action, string $id, string $upload): MediumDetails
    {
        $medium = $this->media->getById($id);
        $path = $this->uploads->path($upload);

        if (null === $path) {
            throw new InvalidInputException($action, ['/upload' => 'There is no upload with this token (any more). Upload the file first.']);
        }

        $draft = $this->drafts->of($id);
        $hash = $this->uploads->hash($upload);

        if (null !== $hash && $hash === ($draft['file']['hash'] ?? $medium->hash)) {
            throw new InvalidInputException($action, ['/upload' => 'The medium has this file already.']);
        }

        $other = null === $hash ? null : $this->withHash($hash);

        if (null !== $other && $other->id !== $id) {
            throw new InvalidInputException($action, ['/upload' => new Message('The file is in the media library already, as "{name}".', ['name' => $other->fileName])]);
        }

        if (null !== $draft && null !== $draft['file'] && $hash === $medium->hash) {
            $this->files->release($draft['file']['name']);
            $draft['file'] = null;
            $this->uploads->remove($upload);
            $this->saveDraft($medium, $draft, null, []);

            return $this->details($medium);
        }

        $name = MediaFiles::newName($id);
        // A new file made with AI says so; one without takes no label away (decision 0251).
        $ai = Images::aiOrigin($path);
        $ai = AiOrigin::None === $ai ? null : $ai;
        $facts = $this->prepare($path, $this->files->target($name), $medium->kind(), $this->uploads->name($upload));

        if ($facts instanceof Message) {
            $this->files->drop($name);

            throw new InvalidInputException($action, ['/upload' => $facts]);
        }

        $this->files->keep($name, $facts['type'], self::keepLocal($facts['type']));

        $file = ['name' => $name, 'fileName' => $this->uploads->name($upload), ...$facts, 'hash' => $hash];
        $this->uploads->remove($upload);

        if ($medium->public || null !== $draft) {
            $draft ??= $this->drafts->start($medium);

            if (null !== $draft['file']) {
                $this->files->release($draft['file']['name']);
            }

            $draft['file'] = $file;
            $this->saveDraft($medium, $draft, null, [], ai: $ai);

            return $this->details($medium);
        }

        // Nobody sees a medium that is not public yet: no draft needed.
        $this->files->release($medium->original());
        $medium = $this->media->update($id, [...self::fileValues($file), 'version' => $medium->version + 1, ...(null === $ai ? [] : ['ai' => $ai->value])]);
        $this->files->removePublic($id);

        return $this->details($medium);
    }

    /**
     * Adds a further file of a video in another format or codec (decision
     * 0177). It counts at once, as it shows the same film.
     *
     * @throws InvalidInputException
     * @throws EntityNotFoundException
     */
    public function addSource(string $action, string $id, string $upload): MediumDetails
    {
        $medium = $this->media->getById($id);
        $path = $this->uploads->path($upload);

        if (null === $path) {
            throw new InvalidInputException($action, ['/upload' => 'There is no upload with this token (any more). Upload the file first.']);
        }

        if (!$medium->isVideo()) {
            throw new InvalidInputException($action, ['/mediaId' => 'Only a video has further files.']);
        }

        if (!Videos::isVideo($path)) {
            throw new InvalidInputException($action, ['/upload' => Videos::notAVideo()]);
        }

        $hash = $this->uploads->hash($upload);
        $sources = $this->sources->of($id);
        $draft = $this->drafts->of($id);
        $seen = null === $draft ? $medium : MediaDrafts::apply($medium, $draft);

        foreach ([$seen->hash, ...array_map(static fn(MediaSource $source): ?string => $source->hash, $sources)] as $other) {
            if (null !== $hash && $hash === $other) {
                throw new InvalidInputException($action, ['/upload' => 'The video has this file already.']);
            }
        }

        $name = MediaFiles::newName($id);
        $facts = $this->videos->prepare($path, $this->files->target($name));

        if ($facts instanceof Message) {
            $this->files->drop($name);

            throw new InvalidInputException($action, ['/upload' => $facts]);
        }

        $family = static fn(string $type, string $codec): string => $type . ' ' . strtolower(explode('.', $codec)[0]);
        $taken = [$family($seen->type, $seen->codec), ...array_map(static fn(MediaSource $source): string => $family($source->type, $source->codec), $sources)];

        if (\in_array($family($facts['type'], $facts['codec']), $taken, true)) {
            $this->files->drop($name);

            throw new InvalidInputException($action, ['/upload' => new Message('The video has a file in {format} already. Delete that one first to replace it.', ['format' => self::format($facts['type'], $facts['codec'])])]);
        }

        $this->files->keep($name, $facts['type'], keepLocal: false);

        $this->sources->insert([
            'mediaId' => $id,
            'file' => $name,
            'type' => $facts['type'],
            'codec' => $facts['codec'],
            'size' => $facts['size'],
            'width' => $facts['width'],
            'height' => $facts['height'],
            'hash' => $hash,
        ]);
        $this->uploads->remove($upload);

        return $this->details($medium);
    }

    /**
     * @throws InvalidInputException
     * @throws EntityNotFoundException
     */
    public function deleteSource(string $action, string $id, string $sourceId): MediumDetails
    {
        $medium = $this->media->getById($id);
        $source = $this->sources->fetchById($sourceId);

        if (null === $source || $source->mediaId !== $id) {
            throw new InvalidInputException($action, ['/sourceId' => 'The video has no file with this id.']);
        }

        $this->sources->delete($source->id);
        $this->files->release($source->file);
        $this->files->removePublic($id);

        return $this->details($medium);
    }

    /**
     * A name for a format, e.g. "MP4 (avc1)".
     */
    public static function format(string $type, string $codec): string
    {
        $container = ['video/mp4' => 'MP4', 'video/webm' => 'WebM'][$type] ?? $type;
        $family = explode('.', $codec)[0];

        return '' === $family ? $container : \sprintf('%s (%s)', $container, $family);
    }

    /**
     * Puts the drafts of media online (decision 0147).
     *
     * @param list<string> $ids
     *
     * @return list<MediumDetails>
     *
     * @throws InvalidInputException
     */
    public function publishDrafts(string $action, array $ids): array
    {
        $errors = [];
        $found = [];

        foreach ($ids as $index => $id) {
            $medium = $this->media->fetchById($id);
            $draft = null === $medium ? null : $this->drafts->of($id);

            if (null === $medium) {
                $errors['/ids/' . $index] = 'There is no medium with this id.';
            } elseif (null === $draft) {
                $errors['/ids/' . $index] = 'The medium has no changes to publish.';
            } else {
                $found[$id] = [$medium, $draft];
            }
        }

        if ([] !== $errors) {
            throw new InvalidInputException($action, $errors);
        }

        $published = [];

        foreach ($found as $id => [$medium, $draft]) {
            if ($draft['version'] !== $medium->version) {
                $this->files->removePublic($id);
            }

            $values = ['focusX' => $draft['focusX'], 'focusY' => $draft['focusY'], 'version' => $draft['version'], 'copyright' => $draft['copyright'], 'ai' => $draft['ai']];

            if (null !== $draft['file']) {
                $this->files->release($medium->original());
                $values += self::fileValues($draft['file']);
            }

            $medium = $this->media->update($id, $values);
            $this->saveTexts($id, $draft['texts']);
            $this->store->publish(MediaDrafts::TYPE, $id);
            $published[] = $this->details($medium);
        }

        return $published;
    }

    /**
     * Removes the draft of a medium; visitors saw it never.
     *
     * @throws InvalidInputException
     * @throws EntityNotFoundException
     */
    public function discard(string $action, string $id): MediumDetails
    {
        $medium = $this->media->getById($id);
        $file = $this->drafts->of($id)['file'] ?? null;

        if (!$this->store->discard(MediaDrafts::TYPE, $id)) {
            throw new InvalidInputException($action, ['/id' => 'The medium has no changes to discard.']);
        }

        if (null !== $file) {
            $this->files->release($file['name']);
        }

        return $this->details($medium);
    }

    /**
     * Removes the medium and its generated files. The originals stay until
     * media:prune, so undo can bring the medium back. A deletion would
     * reach published pages without a draft: as long as published content
     * uses the medium, it is refused (decision 0147).
     *
     * @throws EntityNotFoundException
     * @throws ConflictException
     */
    public function delete(string $id): void
    {
        $medium = $this->media->getById($id);
        $live = $this->livePlaces($id);

        if ($live > 0) {
            throw new ConflictException(new Message('The image is used in {count} published place. Take it out there and publish, then delete it.', ['count' => $live]));
        }
        $file = $this->drafts->of($id)['file'] ?? null;

        foreach ($this->texts->fetch(['where' => ['mediaId' => $id]]) as $text) {
            $this->texts->delete($text->id);
        }

        foreach ($this->tags->fetch(['where' => ['mediaId' => $id]]) as $tag) {
            $this->tags->delete($tag->id);
        }

        foreach ($this->sources->of($id) as $source) {
            $this->sources->delete($source->id);
            $this->files->release($source->file);
        }

        $this->store->discard(MediaDrafts::TYPE, $id);
        $this->media->delete($id);
        $this->files->removePublic($id);
        $this->files->release($medium->original());

        if (null !== $file) {
            $this->files->release($file['name']);
        }
    }

    /**
     * Makes media public, e.g. those a page uses that is published now.
     *
     * @param list<string> $ids Unknown ids are left out
     */
    public function makePublic(array $ids): void
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

    /**
     * The medium as the media library shows it: with its draft.
     */
    public function details(Medium $medium, bool $existing = false): MediumDetails
    {
        $draft = $this->drafts->of($medium->id);
        $texts = $this->drafts->texts($medium->id, true);
        $medium = null === $draft ? $medium : MediaDrafts::apply($medium, $draft);

        $sources = [];

        if ($medium->isVideo()) {
            $preview = $this->urls->video($medium);

            foreach ($this->sources->of($medium->id) as $source) {
                $sources[] = [
                    'id' => $source->id,
                    'type' => $source->type,
                    'codec' => $source->codec,
                    'format' => self::format($source->type, $source->codec),
                    'size' => $source->size,
                    'width' => $source->width,
                    'height' => $source->height,
                    'url' => $this->urls->video($medium, $source),
                ];
            }
        } elseif ($medium->isDocument() || $medium->isVector()) {
            $preview = $this->urls->file($medium);
        } else {
            $formats = $this->urls->formats($medium);
            $preview = $this->urls->url($medium, min(480, $medium->width), 0, \in_array('webp', $formats, true) ? 'webp' : $formats[\count($formats) - 1]);
        }

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
            $preview,
            $medium->createdAt,
            $existing,
            null !== $draft,
            $medium->kind(),
            $medium->codec,
            $sources,
            match (true) {
                $medium->isVideo() => self::format($medium->type, $medium->codec),
                $medium->isDocument() => Documents::format($medium->type),
                default => '',
            },
            $medium->copyright,
            $medium->ai,
        );
    }

    /**
     * Applies changes to the draft of a medium. A draft equal to the
     * published state is removed, as with the fields of the website.
     *
     * @param Draft                                              $draft
     * @param array{int, int}|null                               $focus
     * @param array<string, array{alt?: string, title?: string}> $texts
     */
    private function saveDraft(Medium $medium, array $draft, ?array $focus, array $texts, ?string $copyright = null, ?AiOrigin $ai = null): void
    {
        if (null !== $focus) {
            [$draft['focusX'], $draft['focusY']] = $focus;
        }

        if (null !== $copyright) {
            $draft['copyright'] = $copyright;
        }

        if (null !== $ai) {
            $draft['ai'] = $ai->value;
        }

        foreach ($texts as $language => $text) {
            $draft['texts'][$language] = [
                'alt' => $text['alt'] ?? $draft['texts'][$language]['alt'] ?? '',
                'title' => $text['title'] ?? $draft['texts'][$language]['title'] ?? '',
            ];
        }

        $resized = null !== $draft['file'] || $draft['focusX'] !== $medium->focusX || $draft['focusY'] !== $medium->focusY;
        // New sizes need a new address; going back to the old ones needs none.
        $draft['version'] = $resized ? $medium->version + 1 : $medium->version;
        $published = $this->drafts->start($medium);

        if (!$resized && $draft['copyright'] === $published['copyright'] && $draft['ai'] === $published['ai'] && self::sameTexts($draft['texts'], $published['texts'])) {
            $this->store->discard(MediaDrafts::TYPE, $medium->id);

            return;
        }

        $this->store->saveDraft(MediaDrafts::TYPE, $medium->id, $draft);
    }

    /**
     * How many published places (owner and language) use the medium.
     */
    private function livePlaces(string $id): int
    {
        $places = [];

        foreach ($this->usages->ofMedium($id) as $usage) {
            if ($usage->published) {
                $places[$usage->ownerType . '|' . $usage->ownerId . '|' . $usage->language] = true;
            }
        }

        // Objects of modules are asked, not indexed (decision 0287).
        return \count($places) + $this->objects->published($id);
    }

    /**
     * The medium with this file, published or in a draft (decisions 0146, 0147).
     */
    private function withHash(string $hash): ?Medium
    {
        $medium = $this->media->fetchOne(['where' => ['hash' => $hash]]);

        if (null !== $medium) {
            return $medium;
        }

        $id = $this->drafts->idWithHash($hash);

        return null === $id ? null : $this->media->fetchById($id);
    }

    /**
     * Writes the original of an image, video or document.
     *
     * @param string|null $kind "image", "video" or "document" when the file has to be of that kind
     * @param string      $name The name of the uploaded file, it tells documents apart (decision 0179)
     *
     * @return array{type: string, codec: string, width: int, height: int, size: int}|Message
     */
    private function prepare(string $path, string $target, ?string $kind, string $name): array|Message
    {
        $found = match (true) {
            Videos::isVideo($path) => 'video',
            Images::isImage($path), Svg::isSvg($path) => 'image',
            Documents::isDocument($path, $name) => 'document',
            default => null,
        };

        if (null !== $kind && null === $found) {
            return match ($kind) {
                'video' => Videos::notAVideo(),
                'document' => Documents::notADocument(),
                default => Images::notAnImage(),
            };
        }

        if (null !== $kind && $found !== $kind) {
            return match ($kind) {
                'video' => new Message('Replace a video only with a video.'),
                'document' => new Message('Replace a document only with a document.'),
                default => new Message('Replace an image only with an image.'),
            };
        }

        if ('image' === $found && Svg::isSvg($path)) {
            return self::prepareSvg($path, $target);
        }

        if ('image' === $found) {
            $facts = $this->images->prepare($path, $target);

            return $facts instanceof Message ? $facts : [...$facts, 'codec' => ''];
        }

        return match ($found) {
            'video' => $this->videos->prepare($path, $target),
            'document' => $this->documents->prepare($path, $target, $name),
            default => UploadReceiver::notMedia(),
        };
    }

    /**
     * Writes the clean SVG as original (decision 0182): what could run never reaches the storage.
     *
     * @return array{type: string, codec: string, width: int, height: int, size: int}|Message
     */
    private static function prepareSvg(string $path, string $target): array|Message
    {
        $source = @file_get_contents($path);
        $clean = false === $source ? null : Svg::clean($source);

        if (null === $clean) {
            return new Message('The SVG cannot be read or holds nothing that can be shown.');
        }

        $folder = \dirname($target);

        if ((!is_dir($folder) && !@mkdir($folder, 0o775, true) && !is_dir($folder)) || false === @file_put_contents($target, $clean)) {
            return new Message('The image could not be saved.');
        }

        [$width, $height] = Svg::size($clean);

        return ['type' => Svg::TYPE, 'codec' => '', 'width' => $width, 'height' => $height, 'size' => \strlen($clean)];
    }

    /**
     * Whether the copy of a new original may stay on disk: an image gets
     * its sizes generated next, a video or document is only copied
     * (decision 0268).
     */
    private static function keepLocal(string $type): bool
    {
        return str_starts_with($type, 'image/');
    }

    /**
     * @param DraftFile $file
     *
     * @return array<string, mixed> The columns of a medium for its file
     */
    private static function fileValues(array $file): array
    {
        return [
            'file' => $file['name'],
            'fileName' => $file['fileName'],
            'type' => $file['type'],
            'codec' => $file['codec'],
            'size' => $file['size'],
            'width' => $file['width'],
            'height' => $file['height'],
            'hash' => $file['hash'],
        ];
    }

    /**
     * @param array<string, array{alt: string, title: string}> $one
     * @param array<string, array{alt: string, title: string}> $other
     */
    private static function sameTexts(array $one, array $other): bool
    {
        $one = self::filled($one);
        $other = self::filled($other);
        ksort($one);
        ksort($other);

        return $one === $other;
    }

    /**
     * @param array<string, array{alt: string, title: string}> $texts
     *
     * @return array<string, array{alt: string, title: string}> Without empty languages
     */
    private static function filled(array $texts): array
    {
        $filled = [];

        foreach ($texts as $language => $text) {
            if ('' !== $text['alt'] || '' !== $text['title']) {
                $filled[$language] = $text;
            }
        }

        return $filled;
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
     * The copyright as one line, at most 300 characters (decision 0203).
     */
    private static function cleanCopyright(string $copyright): string
    {
        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', $copyright)), 0, 300);
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
