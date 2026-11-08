<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Page;

use Frootbox\Core\Action\InvalidInputException;
use Frootbox\Core\Component\Components;
use Frootbox\Core\Field\EntryKey;
use Frootbox\Core\Support\Message;

/**
 * The entries of a list field of a page, for the actions that add, move
 * and remove them (decision 0123). An entry is named by its position,
 * e.g. "slides/2", or by its id, e.g. "slides/k3f9x2ab" (decision 0344).
 */
final readonly class ListEntries
{
    /** Path of a list, e.g. "slides" or "slides/0/items". */
    public const string LIST_PATTERN = '^[a-zA-Z][a-zA-Z0-9]*(/' . EntryKey::SEGMENT . '/[a-zA-Z][a-zA-Z0-9]*)*$';

    /** Path of an entry, e.g. "slides/2" or "slides/k3f9x2ab". */
    public const string ENTRY_PATTERN = '^[a-zA-Z][a-zA-Z0-9]*(/' . EntryKey::SEGMENT . '/[a-zA-Z][a-zA-Z0-9]*)*/' . EntryKey::SEGMENT . '$';

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
     * The path of the list and the last segment of an entry path: its
     * position or its id.
     *
     * @return array{string, string}
     */
    public static function split(string $path): array
    {
        $at = (int) strrpos($path, '/');

        return [substr($path, 0, $at), substr($path, $at + 1)];
    }

    /**
     * The position of the entry a segment names; fails if there is none,
     * e.g. because someone removed it meanwhile.
     */
    public function index(string $segment): int
    {
        return EntryKey::position($this->entries, $segment)
            ?? throw new InvalidInputException($this->action, ['/path' => new Message('The list has no entry {index}.', ['index' => $segment])]);
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
        $translations[$this->language] = $translation->withContent($this->field->with($translation->content, $entries));

        return $editor->saveDraft($this->action, $id, $this->page->with(translations: $translations));
    }
}
