<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tag;

use Frootbox\Core\Action\InvalidInputException;
use Frootbox\Core\Language\Languages;
use Frootbox\Core\Support\Message;
use Frootbox\Database\Ulid;

/**
 * The tags of objects for modules (decisions 0233, 0239). A module takes
 * what an editor chose or typed in its actions (check()): ids, keys,
 * presets or new words, found by any of their labels. It keeps the ids
 * in the draft and passes them on when it publishes (sync()) or takes
 * an object offline (clear()). Lists read them with of() and filter
 * with bySlug().
 */
final readonly class ObjectTags
{
    /** How many of the most used tags the form of a module suggests */
    public const int SUGGESTED = 12;

    public function __construct(
        private Tags $tags,
        private TagLinks $links,
        private TagPresets $presets,
        private TagEditor $editor,
        private Languages $languages,
    ) {}

    /**
     * The ids of the tags an action names, creating presets and new words
     * as needed. In a closed list only presets count.
     *
     * @param list<mixed> $entries Ids, keys or labels
     *
     * @return list<string>
     *
     * @throws InvalidInputException
     */
    public function check(string $action, string $type, ?string $space, array $entries, string $pointer = '/tags'): array
    {
        $presets = $this->presets->for($type, $space);
        $closed = $this->presets->closed($type, $space);
        $allowed = array_map(static fn(TagPreset $preset): string => $preset->key, $presets);
        $errors = [];
        $result = [];

        foreach ($entries as $index => $entry) {
            $text = \is_string($entry) ? trim($entry) : '';
            $at = $pointer . '/' . $index;

            if ('' === $text) {
                continue;
            }

            if (mb_strlen($text) > TagEditor::LABEL_LENGTH) {
                $errors[$at] = new Message('must be at most {max} characters long.', ['max' => TagEditor::LABEL_LENGTH]);

                continue;
            }

            $byKey = $this->tags->byKey($text);
            $tag = Ulid::isValid($text) ? $this->tags->resolve($text) : null;
            $tag ??= null === $byKey ? null : $this->tags->resolve($byKey->id);

            if (null === $tag && Ulid::isValid($text)) {
                $errors[$at] = new Message('There is no tag {id}.', ['id' => $text]);

                continue;
            }
            $preset = null === $tag ? array_find($presets, static fn(TagPreset $preset): bool => $preset->isNamed($text)) : null;
            $tag ??= null === $preset ? $this->tags->named($text) : $this->editor->ensure($preset);

            if ($closed && (null === $tag || !\in_array($tag->key, $allowed, true))) {
                $errors[$at] = new Message('"{tag}" is not one of the tags set out here.', ['tag' => $text]);

                continue;
            }

            $tag ??= $this->editor->typed($text, $this->languages->default()->code);

            if (!\in_array($tag->id, $result, true)) {
                $result[] = $tag->id;
            }
        }

        if ([] !== $errors) {
            throw new InvalidInputException($action, $errors);
        }

        return $result;
    }

    /**
     * Sets the tags of a published object; merged tags count as the one
     * they went into, deleted ones are left out.
     *
     * @param list<string> $ids
     */
    public function sync(string $type, string $id, array $ids): void
    {
        $wanted = [];

        foreach ($ids as $tagId) {
            $tag = $this->tags->resolve($tagId);

            if (null !== $tag && !\in_array($tag->id, $wanted, true)) {
                $wanted[] = $tag->id;
            }
        }

        foreach ($this->links->fetch(['where' => ['targetType' => $type, 'targetId' => $id]]) as $link) {
            if (\in_array($link->tagId, $wanted, true)) {
                $wanted = array_values(array_diff($wanted, [$link->tagId]));
            } else {
                $this->links->delete($link->id);
            }
        }

        foreach ($wanted as $tagId) {
            $this->links->insert(['tagId' => $tagId, 'targetType' => $type, 'targetId' => $id]);
        }
    }

    public function clear(string $type, string $id): void
    {
        foreach ($this->links->fetch(['where' => ['targetType' => $type, 'targetId' => $id]]) as $link) {
            $this->links->delete($link->id);
        }
    }

    /**
     * The tags of published objects by object, internal ones included.
     *
     * @param list<string> $ids
     *
     * @return array<string, list<Tag>>
     */
    public function of(string $type, array $ids): array
    {
        $assigned = $this->links->idsOf($type, $ids);

        if ([] === $assigned) {
            return [];
        }

        $byId = [];

        foreach ($this->tags->fetch(['where' => ['id' => array_values(array_unique(array_merge(...array_values($assigned))))]]) as $tag) {
            $byId[$tag->id] = $tag;
        }

        return array_map(static fn(array $tagIds): array => array_values(array_filter(array_map(static fn(string $id): ?Tag => $byId[$id] ?? null, $tagIds))), $assigned);
    }

    /**
     * Tags as visitors see them, e.g. below an article: the label and
     * slug in the language or its fallback, internal ones left out.
     *
     * @param list<Tag> $tags
     *
     * @return list<array{key: string, label: string, slug: string}>
     */
    public function visible(array $tags, string $language): array
    {
        $languages = $this->languages->has($language) ? $this->languages->contentLanguages($language) : [$this->languages->default()->code];
        $visible = [];

        foreach ($tags as $tag) {
            if (!$tag->internal) {
                $visible[] = ['key' => $tag->key, 'label' => $tag->labelIn($languages), 'slug' => $tag->slugIn($languages)];
            }
        }

        return $visible;
    }

    /**
     * The tag with a key, e.g. from the option of a block; a merged one
     * gives the tag it went into.
     */
    public function byKey(string $key): ?Tag
    {
        $tag = $this->tags->byKey($key);

        return null === $tag ? null : $this->tags->resolve($tag->id);
    }

    /**
     * The tag visitors filter by, e.g. ?tag=sustainability: its label in
     * the language or a fallback as a slug, or its key. Never an internal
     * one.
     */
    public function bySlug(string $language, string $slug): ?Tag
    {
        $languages = $this->languages->has($language) ? $this->languages->contentLanguages($language) : [$this->languages->default()->code];

        foreach ($this->tags->current() as $tag) {
            if (!$tag->internal && ($tag->slugIn($languages) === $slug || $tag->key === $slug)) {
                return $tag;
            }
        }

        return null;
    }

    /**
     * What the form of a module offers: the chosen tags, the presets and,
     * in an open list, the most used tags of the type. A preset counts
     * as chosen when a chosen tag has its key.
     *
     * @param list<string> $chosen Ids of tags
     *
     * @return array{closed: bool, options: list<array{value: string, label: string, internal: bool, preset: bool, checked: bool}>, known: list<string>}
     */
    public function choices(string $type, ?string $space, array $chosen): array
    {
        $languages = [$this->languages->default()->code];
        $closed = $this->presets->closed($type, $space);
        $options = [];
        $keys = [];

        foreach ($chosen as $id) {
            $tag = $this->tags->resolve($id);

            if (null !== $tag && !isset($keys[$tag->key])) {
                $keys[$tag->key] = true;
                $options[] = ['value' => $tag->id, 'label' => $tag->labelIn($languages), 'internal' => $tag->internal, 'preset' => false, 'checked' => true];
            }
        }

        foreach ($this->presets->for($type, $space) as $preset) {
            if (!isset($keys[$preset->key])) {
                $keys[$preset->key] = true;
                $tag = $this->tags->byKey($preset->key);
                $options[] = ['value' => $preset->key, 'label' => null === $tag ? $this->presetLabel($preset, $languages) : $tag->labelIn($languages), 'internal' => $preset->internal, 'preset' => true, 'checked' => false];
            }
        }

        $known = [];

        if (!$closed) {
            $counts = $this->links->counts($type);
            arsort($counts);
            $added = 0;

            foreach ($this->tags->current() as $tag) {
                $known[] = $tag->labelIn($languages);
            }

            foreach (array_keys($counts) as $id) {
                $tag = $added < self::SUGGESTED ? $this->tags->fetchById($id) : null;

                if (null !== $tag && null === $tag->mergedInto && !isset($keys[$tag->key])) {
                    $keys[$tag->key] = true;
                    $options[] = ['value' => $tag->id, 'label' => $tag->labelIn($languages), 'internal' => $tag->internal, 'preset' => false, 'checked' => false];
                    ++$added;
                }
            }
        }

        return ['closed' => $closed, 'options' => $options, 'known' => $known];
    }

    /**
     * @param list<string> $languages
     */
    private function presetLabel(TagPreset $preset, array $languages): string
    {
        foreach ([...$languages, ...array_keys($preset->labels)] as $language) {
            if (isset($preset->labels[$language])) {
                return $preset->labels[$language];
            }
        }

        return $preset->key;
    }
}
