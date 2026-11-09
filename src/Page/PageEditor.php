<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Page;

use DateTimeImmutable;
use Frootbox\Core\Action\InvalidInputException;
use Frootbox\Core\Component\Components;
use Frootbox\Core\Component\ContentValidator;
use Frootbox\Core\Language\Languages;
use Frootbox\Core\Media\MediaEditor;
use Frootbox\Core\Media\MediaReferences;
use Frootbox\Core\Media\UsageIndex;
use Frootbox\Core\Revision\RevisionStore;
use Frootbox\Core\Routing\Router;
use Frootbox\Core\Support\Message;
use Frootbox\Core\Support\SortKey;
use Frootbox\Core\Url\AddressSync;
use Frootbox\Database\EntityNotFoundException;

/**
 * The work behind the page actions: checks a page against the tree, the
 * languages and its layout, saves drafts and publishes them
 * (decisions 0099 to 0101).
 */
final readonly class PageEditor
{
    private const array TRANSLATION_KEYS = ['title', 'slug', 'description', 'seoTitle', 'content'];

    /** Longer is cut by search engines anyway (decision 0223). */
    public const int DESCRIPTION_MAX = 300;

    /** About 60 characters fit in search results, more is room to spare (decision 0266). */
    public const int SEO_TITLE_MAX = 120;

    public function __construct(
        private PageReader $reader,
        private Pages $pages,
        private PageTranslations $translations,
        private RevisionStore $store,
        private Components $components,
        private ContentValidator $content,
        private Languages $languages,
        private AddressSync $addresses,
        private Router $router,
        private MediaReferences $references,
        private MediaEditor $media,
        private UsageIndex $usages,
        private PageProtections $protections,
    ) {}

    /**
     * Checks the page against the current pages and saves it as its draft.
     *
     * @throws InvalidInputException
     */
    public function saveDraft(string $action, string $id, PageData $data): PageDetails
    {
        [$checked, $errors] = $this->check($id, $data, $this->reader->nodes(), false);

        if ([] !== $errors) {
            throw new InvalidInputException($action, $errors);
        }

        $this->store->saveDraft(PageReader::TYPE, $id, $checked->toArray());
        $this->usages->page($id);

        return $this->reader->details($id);
    }

    /**
     * The current state of a page for a change: its draft or published state.
     *
     * @throws EntityNotFoundException
     */
    public function current(string $id): PageData
    {
        return $this->reader->draft($id) ?? $this->reader->published($id) ?? throw EntityNotFoundException::for(Pages::TABLE, $id);
    }

    /**
     * Applies translations from an action to the current ones. A language
     * set to null is removed; title, slug and content left out are kept:
     *
     *     {"de": {"title": "Über uns"}, "en": null}
     *
     * @param array<mixed>                   $input
     * @param array<string, TranslationData> $current
     *
     * @return array<string, TranslationData>
     *
     * @throws InvalidInputException
     */
    public function translations(string $action, array $input, array $current = []): array
    {
        if ([] !== $input && array_is_list($input)) {
            throw new InvalidInputException($action, ['/translations' => 'must be an object by language code, e.g. {"de": {"title": "Start"}}.']);
        }

        $errors = [];

        foreach ($input as $language => $value) {
            $language = (string) $language;
            $at = '/translations/' . $language;

            if (null === $value) {
                unset($current[$language]);

                continue;
            }

            if (!\is_array($value) || ([] !== $value && array_is_list($value))) {
                $errors[$at] = 'must be an object with "title" and optionally "slug" and "content".';

                continue;
            }

            $unknown = array_diff(array_map(strval(...), array_keys($value)), self::TRANSLATION_KEYS);

            if ([] !== $unknown) {
                $errors[$at] = \sprintf('Unknown keys: %s. Allowed: %s.', implode(', ', $unknown), implode(', ', self::TRANSLATION_KEYS));
            }

            $old = $current[$language] ?? null;
            $title = \array_key_exists('title', $value) ? $value['title'] : $old?->title;
            $slug = \array_key_exists('slug', $value) ? $value['slug'] ?? '' : $old->slug ?? '';
            $content = \array_key_exists('content', $value) ? $value['content'] ?? [] : $old->content ?? [];
            $description = \array_key_exists('description', $value) ? $value['description'] ?? '' : $old->description ?? '';
            $seoTitle = \array_key_exists('seoTitle', $value) ? $value['seoTitle'] ?? '' : $old->seoTitle ?? '';

            if (!\is_string($title)) {
                $errors[$at . '/title'] = null === $title ? 'is required.' : 'must be a string.';
            }

            if (!\is_string($slug)) {
                $errors[$at . '/slug'] = 'must be a string.';
            }

            if (!\is_array($content) || ([] !== $content && array_is_list($content))) {
                $errors[$at . '/content'] = 'must be an object with "options", "fields" and "areas".';
            }

            if (\is_string($description)) {
                // One line: search engines show it as a snippet.
                $description = trim((string) preg_replace('/\s+/u', ' ', $description));

                if (mb_strlen($description) > self::DESCRIPTION_MAX) {
                    $errors[$at . '/description'] = \sprintf('must be at most %d characters.', self::DESCRIPTION_MAX);
                }
            } else {
                $errors[$at . '/description'] = 'must be a string.';
            }

            if (\is_string($seoTitle)) {
                // One line, it is the whole title in search results (decision 0266).
                $seoTitle = trim((string) preg_replace('/\s+/u', ' ', $seoTitle));

                if (mb_strlen($seoTitle) > self::SEO_TITLE_MAX) {
                    $errors[$at . '/seoTitle'] = \sprintf('must be at most %d characters.', self::SEO_TITLE_MAX);
                }
            } else {
                $errors[$at . '/seoTitle'] = 'must be a string.';
            }

            if (\is_string($title) && \is_string($slug) && \is_array($content) && \is_string($description) && \is_string($seoTitle)) {
                $map = [];

                foreach ($content as $key => $item) {
                    $map[(string) $key] = $item;
                }

                $current[$language] = new TranslationData($title, $slug, $map, $description, $seoTitle);
            }
        }

        if ([] !== $errors) {
            throw new InvalidInputException($action, $errors);
        }

        return $current;
    }

    /**
     * A sort key for the page at a place among the children of a parent:
     * 0 is the first place, null the last.
     */
    public function position(string $id, ?string $parentId, ?int $index): string
    {
        $siblings = array_values(array_filter(
            PageReader::children($this->reader->nodes(), $parentId),
            static fn(PageNode $node): bool => $node->id !== $id,
        ));
        $index = null === $index ? \count($siblings) : min(max(0, $index), \count($siblings));
        $before = $index > 0 ? $siblings[$index - 1]->data->position : null;
        $after = $siblings[$index]->data->position ?? null;

        // Restored pages can share a key with a sibling; then the new one goes after both.
        if (null !== $before && null !== $after && strcmp($before, $after) >= 0) {
            $after = null;
        }

        return SortKey::between($before, $after);
    }

    /**
     * Publishes the drafts of several pages as one change. Parents that
     * are only drafts must be part of the list.
     *
     * @param list<string> $ids
     *
     * @return list<PageDetails>
     *
     * @throws InvalidInputException
     */
    public function publish(string $action, array $ids): array
    {
        $ids = array_values(array_unique($ids));

        if ([] === $ids) {
            throw new InvalidInputException($action, ['/ids' => 'needs at least one page id.']);
        }

        $errors = [];
        $drafts = [];

        foreach ($ids as $index => $id) {
            $draft = $this->reader->draft($id);

            if (null === $draft) {
                $errors['/ids/' . $index] = \sprintf('The page %s has no draft to publish.', $id);
            } else {
                $drafts[$id] = $draft;
            }
        }

        $nodes = $this->reader->nodesAfterPublishing($ids);

        foreach ($drafts as $id => $draft) {
            $at = '/ids/' . array_search($id, $ids, true);

            if (null !== $draft->parentId && !isset($nodes[$draft->parentId])) {
                $errors[$at] = \sprintf('The parent page %s is not published. Publish both together: {"ids": ["%s", "%s"]}.', $draft->parentId, $draft->parentId, $id);

                continue;
            }

            [$checked, $found] = $this->check($id, $draft, $nodes, true);
            $drafts[$id] = $checked;

            foreach ($found as $pointer => $message) {
                $errors[$at . $pointer] = $message;
            }
        }

        if ([] !== $errors) {
            throw new InvalidInputException($action, $errors);
        }

        $media = [];

        foreach ($this->parentsFirst($drafts, $nodes) as $id => $data) {
            $this->store->saveDraft(PageReader::TYPE, $id, $data->toArray());
            $this->write($id, $data);
            $this->store->publish(PageReader::TYPE, $id);
            $this->usages->page($id);
            array_push($media, ...$this->references->inPage($data));
        }

        // Media become public with the first published page that uses them.
        $this->media->makePublic($media);

        $this->addresses->sync();

        return array_map($this->reader->details(...), $ids);
    }

    /**
     * Removes the draft. A page that was never published is gone then.
     *
     * @return PageDetails|null null if the page is gone
     *
     * @throws InvalidInputException
     */
    public function discard(string $action, string $id): ?PageDetails
    {
        if (null === $this->reader->draft($id)) {
            throw new InvalidInputException($action, ['/id' => 'The page has no draft.']);
        }

        $published = $this->reader->published($id);

        if (null === $published) {
            $this->assertNoChildren($action, $id);
        }

        $this->store->discard(PageReader::TYPE, $id);
        $this->usages->page($id);

        return null === $published ? null : $this->reader->details($id);
    }

    /**
     * Deletes the published state and the draft right away. The versions
     * stay and can be restored.
     *
     * @throws EntityNotFoundException
     * @throws InvalidInputException
     */
    public function delete(string $action, string $id): void
    {
        if (!isset($this->reader->nodes()[$id])) {
            throw EntityNotFoundException::for(Pages::TABLE, $id);
        }

        $this->assertNoChildren($action, $id);

        foreach ($this->translations->of($id) as $translation) {
            $this->translations->delete($translation->id);
        }

        $this->pages->delete($id);

        // The password goes with the page (decision 0358).
        $protection = $this->protections->of($id);

        if (null !== $protection) {
            $this->protections->delete($protection->id);
        }

        $this->store->discard(PageReader::TYPE, $id);
        $this->store->archive(PageReader::TYPE, $id);
        $this->usages->page($id);
        $this->addresses->sync();
    }

    /**
     * Takes a page and all its subpages offline. They stay as drafts, the
     * published versions are archived (decision 0107).
     *
     * @return PageDetails The page, now a draft
     *
     * @throws InvalidInputException
     */
    public function unpublish(string $action, string $id): PageDetails
    {
        $published = $this->reader->nodesAfterPublishing([]);

        if (!isset($published[$id])) {
            throw new InvalidInputException($action, ['/id' => \sprintf('The page %s is not published.', $id)]);
        }

        foreach (array_reverse(self::branch($published, $id)) as $branchId) {
            $data = $this->reader->published($branchId);

            if (null !== $data && null === $this->reader->draft($branchId)) {
                $this->store->saveDraft(PageReader::TYPE, $branchId, $data->toArray());
            }

            foreach ($this->translations->of($branchId) as $translation) {
                $this->translations->delete($translation->id);
            }

            $this->pages->delete($branchId);
            $this->store->archive(PageReader::TYPE, $branchId);
            $this->usages->page($branchId);
        }

        $this->addresses->sync();

        return $this->reader->details($id);
    }

    /**
     * Locks a published page for visitors right away, or unlocks it with
     * Reach::None (decision 0117). The lock is not part of the draft.
     *
     * @throws InvalidInputException
     * @throws EntityNotFoundException
     */
    public function lock(string $action, string $id, Reach $reach): PageDetails
    {
        $this->reader->details($id);

        if (null === $this->pages->fetchById($id)) {
            throw new InvalidInputException($action, ['/id' => 'Only published pages can be locked.']);
        }

        $this->pages->update($id, ['locked' => $reach]);

        return $this->reader->details($id);
    }

    /**
     * Plans when the draft is published and when the page goes offline;
     * null for no time.
     *
     * @throws InvalidInputException
     * @throws EntityNotFoundException
     */
    public function schedule(string $action, string $id, ?DateTimeImmutable $publishAt, ?DateTimeImmutable $unpublishAt): PageDetails
    {
        $this->reader->details($id);
        $errors = [];

        if (!$this->store->publishAt(PageReader::TYPE, $id, $publishAt) && null !== $publishAt) {
            $errors['/publishAt'] = 'The page has no draft to publish.';
        }

        if (!$this->store->unpublishAt(PageReader::TYPE, $id, $unpublishAt) && null !== $unpublishAt) {
            $errors['/unpublishAt'] = 'The page is not published.';
        }

        if (null !== $publishAt && null !== $unpublishAt && $unpublishAt <= $publishAt) {
            $errors['/unpublishAt'] = 'must be after "publishAt".';
        }

        if ([] !== $errors) {
            throw new InvalidInputException($action, $errors);
        }

        return $this->reader->details($id);
    }

    /**
     * The page and all pages below it, parents first.
     *
     * @param array<string, PageNode> $nodes
     *
     * @return list<string>
     */
    private static function branch(array $nodes, string $id): array
    {
        $ids = [$id];

        foreach (PageReader::children($nodes, $id) as $child) {
            array_push($ids, ...self::branch($nodes, $child->id));
        }

        return $ids;
    }

    /**
     * Checks a page against the other pages, the languages and its layout,
     * and fills in slugs and block ids.
     *
     * @param array<string, PageNode> $nodes
     *
     * @return array{PageData, array<string, string|Message>} The page, and messages by JSON pointer
     */
    private function check(string $id, PageData $data, array $nodes, bool $complete): array
    {
        $errors = $this->checkParent($id, $data->parentId, $nodes);
        // What is stored keeps its orphaned parts (decision 0276).
        $stored = $this->reader->draft($id) ?? $this->reader->published($id);
        $sameLayout = $stored?->layout === $data->layout;
        $layout = $this->components->layout($data->layout);
        $orphaned = null === $layout && $sameLayout;
        $layout ??= $orphaned ? $this->components->fallbackLayout(null !== $data->plugin) : null;
        $variant = $data->variant;

        if (null === $layout) {
            $errors['/layout'] = \sprintf('There is no layout "%s".', $data->layout);
        } elseif (null !== $variant && !isset($layout->variants[$variant])) {
            if (!$orphaned && !($sameLayout && $stored->variant === $variant)) {
                $errors['/variant'] = \sprintf('%s has no variant "%s". Variants: %s.', $layout->name, $variant, implode(', ', array_keys($layout->variants)));
            }

            $variant = null;
        }

        if ([] === $data->translations) {
            $errors['/translations'] = 'needs at least one language, e.g. {"de": {"title": "Start"}}.';
        }

        // A space of a plugin has one page, in a layout with plugin() (decision 0232).
        if (null !== $data->plugin) {
            if (null !== $layout && !$layout->plugin && !$orphaned) {
                $errors['/layout'] = 'The layout has no place for the plugin of the page. Choose a layout with one or take the plugin away first.';
            }

            foreach ($nodes as $node) {
                if ($node->id !== $id && null !== $node->data->plugin && $node->data->plugin->is($data->plugin->type, $data->plugin->space)) {
                    $errors['/plugin'] = \sprintf('The page %s shows this space already. A space has one page.', $node->id);
                }
            }
        }

        $siblings = array_filter($nodes, static fn(PageNode $node): bool => $node->id !== $id && $node->data->parentId === $data->parentId);
        $translations = [];

        foreach ($data->translations as $language => $translation) {
            $at = '/translations/' . $language;

            if (!$this->languages->has($language)) {
                $errors[$at] = \sprintf('"%s" is not a language of the website. Languages: %s.', $language, implode(', ', $this->languages->codes()));

                continue;
            }

            $title = trim($translation->title);
            $slug = null === $data->parentId ? '' : ('' === $translation->slug ? Slug::fromTitle($title, $language) : Slug::normalize($translation->slug));

            if ('' === $title || mb_strlen($title) > 255) {
                $errors[$at . '/title'] = '' === $title ? 'must not be empty.' : 'must be at most 255 characters.';
            }

            if (null !== $data->parentId) {
                $error = $this->checkSlug($slug, $language, $siblings, null === ($nodes[$data->parentId] ?? null)?->data->parentId);

                if (null !== $error) {
                    $errors[$at . '/slug'] = $error;
                }
            }

            $content = $translation->content;

            if (null !== $layout) {
                [$content, $found] = $this->content->check($layout, $variant, $translation->content, $complete, $stored?->translations[$language]->content ?? null);

                foreach ($found as $pointer => $message) {
                    $errors[$at . '/content' . ('/' === $pointer ? '' : $pointer)] = $message;
                }
            }

            $translations[$language] = new TranslationData($title, $slug, $content, $translation->description, $translation->seoTitle);
        }

        return [new PageData($data->parentId, $data->position, $data->layout, $data->variant, $translations, $data->excludeFromSearchEngines, $data->excludeFromSiteSearch, $data->plugin), $errors];
    }

    /**
     * @param array<string, PageNode> $nodes
     *
     * @return array<string, string>
     */
    private function checkParent(string $id, ?string $parentId, array $nodes): array
    {
        if (null === $parentId) {
            foreach ($nodes as $node) {
                if ($node->id !== $id && null === $node->data->parentId) {
                    return ['/parentId' => \sprintf('is required: there already is a start page (%s), and every other page has a parent.', $node->id)];
                }
            }

            return [];
        }

        if (!isset($nodes[$parentId])) {
            return ['/parentId' => \sprintf('There is no page %s.', $parentId)];
        }

        // Walk up from the new parent; meeting the page itself would make a loop.
        $seen = [];

        for ($current = $parentId; null !== $current && !isset($seen[$current]); $current = $nodes[$current]->data->parentId ?? null) {
            if ($current === $id) {
                return ['/parentId' => 'must not be the page itself or one of its subpages.'];
            }

            $seen[$current] = true;
        }

        return [];
    }

    /**
     * @param array<string, PageNode> $siblings
     * @param bool                    $belowStart whether the page is directly below the start page
     */
    private function checkSlug(string $slug, string $language, array $siblings, bool $belowStart): ?string
    {
        if ('' === $slug) {
            return 'is required: the title has no letters or digits to make one from.';
        }

        if (!Slug::isValid($slug)) {
            return \sprintf('must be lower case letters, 0-9 and hyphens, at most %d characters, e.g. "about-us". Latin, Cyrillic and Greek only as a-z.', Slug::MAX_LENGTH);
        }

        $reserved = array_values(array_unique([...Slug::RESERVED, ...$this->router->reservedSegments(), ...$this->languages->prefixes()]));

        if ($belowStart && \in_array($slug, $reserved, true)) {
            return \sprintf('"%s" is reserved directly below the start page. Reserved: %s.', $slug, implode(', ', $reserved));
        }

        foreach ($siblings as $sibling) {
            $other = $sibling->data->translations[$language] ?? null;

            if (null !== $other && $other->slug === $slug) {
                return \sprintf('is already used by the page "%s" (%s) next to it.', $other->title, $sibling->id);
            }
        }

        return null;
    }

    /**
     * @throws InvalidInputException
     */
    private function assertNoChildren(string $action, string $id): void
    {
        $children = PageReader::children($this->reader->nodes(), $id);

        if ([] !== $children) {
            throw new InvalidInputException($action, ['/id' => \sprintf('The page has %d subpages. Delete or move them first.', \count($children))]);
        }
    }

    /**
     * Writes the published state into pages and page_translations.
     */
    private function write(string $id, PageData $data): void
    {
        $values = [
            'parentId' => $data->parentId,
            'position' => $data->position,
            'layout' => $data->layout,
            'variant' => $data->variant,
            'excludeFromSearchEngines' => $data->excludeFromSearchEngines,
            'excludeFromSiteSearch' => $data->excludeFromSiteSearch,
            'pluginType' => $data->plugin?->type,
            'pluginSpace' => $data->plugin?->space,
            'pluginDetails' => $data->plugin->details ?? false,
            'pluginCategories' => $data->plugin->categories ?? false,
        ];

        if (null === $this->pages->fetchById($id)) {
            $this->pages->insert(['id' => $id, ...$values]);
        } else {
            $this->pages->update($id, $values);
        }

        $existing = $this->translations->of($id);

        foreach ($data->translations as $language => $translation) {
            $row = ['title' => $translation->title, 'slug' => $translation->slug, 'description' => $translation->description, 'seoTitle' => $translation->seoTitle, 'content' => $translation->content];

            if (isset($existing[$language])) {
                $this->translations->update($existing[$language]->id, $row);
            } else {
                $this->translations->insert(['pageId' => $id, 'language' => $language, ...$row]);
            }
        }

        foreach (array_diff_key($existing, $data->translations) as $removed) {
            $this->translations->delete($removed->id);
        }
    }

    /**
     * @param array<string, PageData> $drafts
     * @param array<string, PageNode> $nodes
     *
     * @return array<string, PageData>
     */
    private function parentsFirst(array $drafts, array $nodes): array
    {
        $depth = static function (string $id) use ($nodes): int {
            $level = 0;

            for ($current = $nodes[$id]->data->parentId ?? null; null !== $current && $level < 1000; $current = $nodes[$current]->data->parentId ?? null) {
                ++$level;
            }

            return $level;
        };

        uksort($drafts, static fn(string $a, string $b): int => $depth($a) <=> $depth($b));

        return $drafts;
    }
}
