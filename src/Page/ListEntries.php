<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Page;

use Frootbox\Core\Action\InvalidInputException;
use Frootbox\Core\Component\Components;
use Frootbox\Core\Support\Message;

/**
 * The entries of a list field of a page, for the actions that add, move
 * and remove them (decision 0123). Entries have no ids, so they are
 * named by their position, e.g. "slides/2".
 */
final readonly class ListEntries
{
    /** Path of a list, e.g. "slides" or "slides/0/items". */
    public const string LIST_PATTERN = '^[a-zA-Z][a-zA-Z0-9]*(/(0|[1-9][0-9]*)/[a-zA-Z][a-zA-Z0-9]*)*$';

    /** Path of an entry, e.g. "slides/2". */
    public const string ENTRY_PATTERN = '^[a-zA-Z][a-zA-Z0-9]*(/(0|[1-9][0-9]*)/[a-zA-Z][a-zA-Z0-9]*)*/(0|[1-9][0-9]*)$';

    /**
     * @param list<mixed> $entries
     */
    private function __construct(
        private string $action,
        private ContentField $field,
        private PageData $page,
        private string $language,
        public array $entries,
    ) {}

    public static function of(string $action, Components $components, PageData $page, string $language, ?string $block, string $path): self
    {
        $translation = $page->translations[$language] ?? throw new InvalidInputException($action, ['/language' => 'The page has no translation in this language.']);
        $field = ContentField::find($components, $page->layout, $translation->content, $block, $path);

        if (\is_array($field)) {
            throw new InvalidInputException($action, $field);
        }

        if ([] === $field->field->fields) {
            throw new InvalidInputException($action, ['/path' => new Message('"{path}" is no list.', ['path' => $path])]);
        }

        $entries = $field->value($translation->content);

        return new self($action, $field, $page, $language, \is_array($entries) ? array_values($entries) : []);
    }

    /**
     * The path of the list and the position of an entry path.
     *
     * @return array{string, int}
     */
    public static function split(string $path): array
    {
        $at = (int) strrpos($path, '/');

        return [substr($path, 0, $at), (int) substr($path, $at + 1)];
    }

    /**
     * Fails if there is no entry at the position.
     */
    public function check(int $index, string $key = '/path'): void
    {
        if (!\array_key_exists($index, $this->entries)) {
            throw new InvalidInputException($this->action, [$key => new Message('The list has no entry {index}.', ['index' => $index])]);
        }
    }

    /**
     * Saves the entries as the value of the list.
     *
     * @param list<mixed> $entries
     */
    public function save(PageEditor $editor, string $id, array $entries): PageDetails
    {
        $translations = $this->page->translations;
        $translation = $translations[$this->language];
        $translations[$this->language] = new TranslationData($translation->title, $translation->slug, $this->field->with($translation->content, $entries));

        return $editor->saveDraft($this->action, $id, $this->page->with(translations: $translations));
    }
}
