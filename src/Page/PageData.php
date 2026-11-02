<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Page;

use UnexpectedValueException;

/**
 * Everything about a page as one snapshot, the way drafts and versions
 * store it (decision 0101):
 *
 *     {"parentId": "01J…", "position": "i", "layout": "acme/website/Page", "variant": null,
 *      "excludeFromSearchEngines": "none", "excludeFromSiteSearch": "branch",
 *      "translations": {"de": {"title": "Über uns", "slug": "ueber-uns", "content": {…}}}}
 *
 * The two exclusions reach the page only or its whole branch
 * (decision 0117); snapshots without them mean none.
 */
final readonly class PageData
{
    /**
     * @param array<string, TranslationData> $translations by language
     */
    public function __construct(
        public ?string $parentId,
        public string $position,
        public string $layout,
        public ?string $variant,
        public array $translations,
        public Reach $excludeFromSearchEngines = Reach::None,
        public Reach $excludeFromSiteSearch = Reach::None,
    ) {}

    /**
     * @param array<mixed> $data A snapshot from toArray()
     */
    public static function fromArray(array $data): self
    {
        $translations = [];

        foreach (\is_array($data['translations'] ?? null) ? $data['translations'] : [] as $language => $translation) {
            if (!\is_array($translation) || !\is_string($translation['title'] ?? null) || !\is_string($translation['slug'] ?? null)) {
                throw new UnexpectedValueException(\sprintf('The page snapshot has an invalid translation "%s".', $language));
            }

            $content = [];

            foreach (\is_array($translation['content'] ?? null) ? $translation['content'] : [] as $key => $value) {
                $content[(string) $key] = $value;
            }

            $translations[(string) $language] = new TranslationData($translation['title'], $translation['slug'], $content);
        }

        $parentId = $data['parentId'] ?? null;
        $position = $data['position'] ?? null;
        $layout = $data['layout'] ?? null;
        $variant = $data['variant'] ?? null;

        if ((null !== $parentId && !\is_string($parentId)) || !\is_string($position) || !\is_string($layout) || (null !== $variant && !\is_string($variant))) {
            throw new UnexpectedValueException('The page snapshot needs parentId, position and layout.');
        }

        return new self($parentId, $position, $layout, $variant, $translations, self::reach($data, 'excludeFromSearchEngines'), self::reach($data, 'excludeFromSiteSearch'));
    }

    public static function fromPage(Page $page, PageTranslation ...$translations): self
    {
        $data = [];

        foreach ($translations as $translation) {
            $content = [];

            foreach ($translation->content as $key => $value) {
                $content[(string) $key] = $value;
            }

            $data[$translation->language] = new TranslationData($translation->title, $translation->slug, $content);
        }

        return new self($page->parentId, $page->position, $page->layout, $page->variant, $data, $page->excludeFromSearchEngines, $page->excludeFromSiteSearch);
    }

    /**
     * @return array{parentId: string|null, position: string, layout: string, variant: string|null, excludeFromSearchEngines: string, excludeFromSiteSearch: string, translations: array<string, array{title: string, slug: string, content: array<string, mixed>}>}
     */
    public function toArray(): array
    {
        $translations = [];

        foreach ($this->translations as $language => $translation) {
            $translations[$language] = ['title' => $translation->title, 'slug' => $translation->slug, 'content' => $translation->content];
        }

        return [
            'parentId' => $this->parentId,
            'position' => $this->position,
            'layout' => $this->layout,
            'variant' => $this->variant,
            'excludeFromSearchEngines' => $this->excludeFromSearchEngines->value,
            'excludeFromSiteSearch' => $this->excludeFromSiteSearch->value,
            'translations' => $translations,
        ];
    }

    /**
     * @param array<string, TranslationData> $translations
     */
    public function with(
        ?string $parentId = null,
        ?string $position = null,
        ?string $layout = null,
        ?string $variant = null,
        ?array $translations = null,
        ?Reach $excludeFromSearchEngines = null,
        ?Reach $excludeFromSiteSearch = null,
    ): self {
        return new self(
            $parentId ?? $this->parentId,
            $position ?? $this->position,
            $layout ?? $this->layout,
            $variant ?? $this->variant,
            $translations ?? $this->translations,
            $excludeFromSearchEngines ?? $this->excludeFromSearchEngines,
            $excludeFromSiteSearch ?? $this->excludeFromSiteSearch,
        );
    }

    /**
     * @param array<mixed> $data
     */
    private static function reach(array $data, string $key): Reach
    {
        $value = $data[$key] ?? null;

        return \is_string($value) ? Reach::tryFrom($value) ?? Reach::None : Reach::None;
    }
}
