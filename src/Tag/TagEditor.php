<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tag;

use Frootbox\Core\Action\InvalidInputException;
use Frootbox\Core\Language\Languages;
use Frootbox\Core\Page\Slug;
use Frootbox\Core\Support\Message;

/**
 * Writes the stock of tags (decision 0233). Tags have no draft: changes
 * apply at once and can be undone. The key stays as it was made; a
 * label belongs to one tag only, without regard to case.
 */
final readonly class TagEditor
{
    public const int LABEL_LENGTH = 60;

    public function __construct(
        private Tags $tags,
        private TagLinks $links,
        private Languages $languages,
    ) {}

    /**
     * @param array<mixed> $labels
     *
     * @throws InvalidInputException
     */
    public function create(string $action, array $labels, ?string $key, bool $internal): Tag
    {
        $checked = $this->labels($action, null, $labels, []);

        if (null !== $key) {
            if (1 !== preg_match('/' . TagPresets::KEY_PATTERN . '/', $key) || \strlen($key) > 100) {
                throw new InvalidInputException($action, ['/key' => 'must be a-z, 0-9 and hyphens, e.g. "featured".']);
            }

            if (null !== $this->tags->byKey($key)) {
                throw new InvalidInputException($action, ['/key' => new Message('Another tag has the key "{key}" already.', ['key' => $key])]);
            }
        }

        if ([] === $checked && !$internal) {
            throw new InvalidInputException($action, ['/labels' => 'needs at least one language, e.g. {"de": "Nachhaltigkeit"}.']);
        }

        if ([] === $checked && null === $key) {
            throw new InvalidInputException($action, ['/key' => 'is required for an internal tag without labels.']);
        }

        return $this->tags->insert(['key' => $key ?? $this->newKey($checked), 'labels' => $checked, 'internal' => $internal, 'mergedInto' => null]);
    }

    /**
     * A tag from a preset, or the one that has its key already.
     */
    public function ensure(TagPreset $preset): Tag
    {
        return $this->tags->byKey($preset->key) ?? $this->tags->insert(['key' => $preset->key, 'labels' => $preset->labels, 'internal' => $preset->internal, 'mergedInto' => null]);
    }

    /**
     * A new tag an editor typed, with the text as its label in a language.
     */
    public function typed(string $text, string $language): Tag
    {
        $labels = [$language => $text];

        return $this->tags->insert(['key' => $this->newKey($labels), 'labels' => $labels, 'internal' => false, 'mergedInto' => null]);
    }

    /**
     * Labels by language: languages left out stay, null or empty removes
     * one.
     *
     * @param array<mixed>|null $labels
     *
     * @throws InvalidInputException
     */
    public function update(string $action, string $id, ?array $labels, ?bool $internal): Tag
    {
        $tag = $this->find($action, $id);
        $values = [];

        if (null !== $labels) {
            $values['labels'] = $this->labels($action, $tag->id, $labels, $tag->labels);

            if ([] === $values['labels'] && !($internal ?? $tag->internal)) {
                throw new InvalidInputException($action, ['/labels' => 'needs at least one language, e.g. {"de": "Nachhaltigkeit"}.']);
            }
        }

        if (null !== $internal) {
            $values['internal'] = $internal;
        }

        return [] === $values ? $tag : $this->tags->update($tag->id, $values);
    }

    /**
     * Puts the objects of a tag on another one. The merged tag stays as
     * a pointer, so drafts and editors that name it, by id, key or label,
     * get the other one; languages the other one lacks get its labels.
     *
     * @throws InvalidInputException
     */
    public function merge(string $action, string $id, string $into): Tag
    {
        $tag = $this->find($action, $id);
        $target = $this->tags->fetchById($into);

        if (null === $target || null !== $target->mergedInto) {
            throw new InvalidInputException($action, ['/into' => 'There is no tag with this id.']);
        }

        if ($target->id === $tag->id) {
            throw new InvalidInputException($action, ['/into' => 'A tag cannot be merged into itself.']);
        }

        foreach ($this->links->fetch(['where' => ['tagId' => $tag->id]]) as $link) {
            $taken = $this->links->fetchOne(['where' => ['tagId' => $target->id, 'targetType' => $link->targetType, 'targetId' => $link->targetId]]);

            if (null === $taken) {
                $this->links->update($link->id, ['tagId' => $target->id]);
            } else {
                $this->links->delete($link->id);
            }
        }

        foreach ($this->tags->fetch(['where' => ['mergedInto' => $tag->id]]) as $earlier) {
            $this->tags->update($earlier->id, ['mergedInto' => $target->id]);
        }

        $this->tags->update($tag->id, ['mergedInto' => $target->id]);
        $labels = $target->labels;

        foreach ($tag->labels as $language => $label) {
            if (\is_string($label) && null === $target->label((string) $language)) {
                $labels[(string) $language] = $label;
            }
        }

        return $labels === $target->labels ? $target : $this->tags->update($target->id, ['labels' => $labels]);
    }

    /**
     * Deletes a tag; the objects stay, only without it.
     *
     * @throws InvalidInputException
     */
    public function delete(string $action, string $id): void
    {
        $tag = $this->find($action, $id);

        foreach ($this->links->fetch(['where' => ['tagId' => $tag->id]]) as $link) {
            $this->links->delete($link->id);
        }

        foreach ($this->tags->fetch(['where' => ['mergedInto' => $tag->id]]) as $earlier) {
            $this->tags->delete($earlier->id);
        }

        $this->tags->delete($tag->id);
    }

    private function find(string $action, string $id): Tag
    {
        $tag = $this->tags->fetchById($id);

        return null === $tag || null !== $tag->mergedInto ? throw new InvalidInputException($action, ['/id' => 'There is no tag with this id.']) : $tag;
    }

    /**
     * @param array<string, string> $labels
     */
    private function newKey(array $labels): string
    {
        $language = (string) array_key_first($labels);
        $base = Slug::latin($labels[$language] ?? '', $language);
        $base = '' === $base ? 'tag' : substr($base, 0, 90);
        $key = $base;

        for ($number = 2; null !== $this->tags->byKey($key); ++$number) {
            $key = $base . '-' . $number;
        }

        return $key;
    }

    /**
     * @param array<mixed> $input
     * @param array<mixed> $current
     *
     * @return array<string, string>
     *
     * @throws InvalidInputException
     */
    private function labels(string $action, ?string $id, array $input, array $current): array
    {
        if ([] !== $input && array_is_list($input)) {
            throw new InvalidInputException($action, ['/labels' => 'must be an object by language code, e.g. {"de": "Nachhaltigkeit"}.']);
        }

        $errors = [];
        $result = [];

        foreach ($current as $language => $label) {
            if (\is_string($label) && '' !== $label) {
                $result[(string) $language] = $label;
            }
        }

        foreach ($input as $language => $label) {
            $language = (string) $language;
            $at = '/labels/' . $language;
            $label = \is_string($label) ? trim($label) : '';

            if ('' === $label) {
                unset($result[$language]);

                continue;
            }

            if (!$this->languages->has($language)) {
                $errors[$at] = new Message('"{language}" is no language of the website.', ['language' => $language]);

                continue;
            }

            if (mb_strlen($label) > self::LABEL_LENGTH) {
                $errors[$at] = new Message('must be at most {max} characters long.', ['max' => self::LABEL_LENGTH]);

                continue;
            }

            $other = $this->tags->named($label);

            if (null !== $other && $other->id !== $id) {
                $errors[$at] = new Message('The tag "{tag}" has this label already. Merge them instead.', ['tag' => $other->labelIn([$language])]);

                continue;
            }

            $result[$language] = $label;
        }

        if ([] !== $errors) {
            throw new InvalidInputException($action, $errors);
        }

        $sorted = [];

        foreach ($this->languages->sort($result) as $language => $label) {
            $sorted[(string) $language] = $label;
        }

        return $sorted;
    }
}
