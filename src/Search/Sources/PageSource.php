<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Search\Sources;

use Frootbox\Core\Component\Component;
use Frootbox\Core\Component\Components;
use Frootbox\Core\Field\FieldTypes;
use Frootbox\Core\Page\PageReader;
use Frootbox\Core\Page\Pages;
use Frootbox\Core\Page\PageTranslations;
use Frootbox\Core\Revision\Revisions;
use Frootbox\Core\Search\AsSearchSource;
use Frootbox\Core\Search\SearchEntry;
use Frootbox\Core\Search\SearchSource;

/**
 * Pages in every language as editors see them, the draft first: title,
 * address and the text of all fields of layout and blocks (decision 0214).
 */
#[AsSearchSource('page', 'Pages')]
final readonly class PageSource implements SearchSource
{
    /** At most this much text of a page per language. */
    public const int MAX_TEXT = 20000;

    public function __construct(
        private PageReader $reader,
        private Components $components,
        private FieldTypes $types,
    ) {}

    public function changed(string $table, array $row): array
    {
        $id = match ($table) {
            Pages::TABLE => $row['id'] ?? null,
            PageTranslations::TABLE => $row['page_id'] ?? null,
            Revisions::TABLE => PageReader::TYPE === ($row['type'] ?? null) ? $row['entity_id'] ?? null : null,
            default => null,
        };

        return \is_string($id) ? [$id] : [];
    }

    public function items(): iterable
    {
        return array_keys($this->reader->nodes());
    }

    public function entries(array $items): iterable
    {
        foreach ($items as $id) {
            $data = $this->reader->draft($id) ?? $this->reader->published($id);
            $layout = null === $data ? null : $this->components->layout($data->layout);

            foreach (null === $data ? [] : $data->translations as $language => $translation) {
                $text = implode("\n", array_filter([$translation->slug, null === $layout ? '' : $this->text($layout, $translation->content)], static fn(string $part): bool => '' !== $part));

                yield new SearchEntry(
                    item: $id,
                    title: $translation->title,
                    text: mb_substr($text, 0, self::MAX_TEXT),
                    permission: 'page.show',
                    language: $language,
                    scopeKind: 'page',
                    scopeId: $id,
                );
            }
        }
    }

    /**
     * @param array<mixed> $body With "fields" and "areas"
     */
    private function text(Component $component, array $body): string
    {
        $texts = [\is_array($body['fields'] ?? null) ? $this->types->text($component->fields, $body['fields']) : ''];

        foreach (\is_array($body['areas'] ?? null) ? $body['areas'] : [] as $blocks) {
            foreach (\is_array($blocks) ? $blocks : [] as $block) {
                $child = \is_array($block) && \is_string($block['block'] ?? null) ? $this->components->block($block['block']) : null;

                if (null !== $child) {
                    $texts[] = $this->text($child, $block);
                }
            }
        }

        return implode("\n", array_filter($texts, static fn(string $text): bool => '' !== $text));
    }
}
