<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Page;

use Frootbox\Core\Language\Language;
use Frootbox\Core\Language\Languages;
use Frootbox\Core\Url\Addresses;
use Frootbox\Core\Url\DraftAddresses;
use Frootbox\Core\Url\Target;

/**
 * Finds the published page at a path in a language through its current
 * address (decision 0105). In the editing mode the addresses of the
 * drafts come first and pages show their drafts (decision 0119).
 */
final readonly class PageResolver
{
    public function __construct(
        private Addresses $addresses,
        private PageReader $reader,
        private Languages $languages,
        private EditMode $editMode,
        private DraftAddresses $drafts,
    ) {}

    /**
     * @param string $path The path without language prefix, e.g. "ueber-uns/team"
     */
    public function resolve(Language $language, string $path): ?ResolvedPage
    {
        $path = trim($path, '/');
        $draft = $this->editMode->active() ? $this->drafts->at($language->code, $path) : null;

        if (null !== $draft) {
            $data = $this->reader->draft($draft->pageId) ?? $this->reader->published($draft->pageId);

            return null === $data || !isset($data->translations[$draft->contentLanguage])
                ? null
                : new ResolvedPage($draft->pageId, $data, $language->code, $data->translations[$draft->contentLanguage], $language->path($path), $draft->contentLanguage);
        }

        $address = $this->addresses->at($language->code, $path);
        $target = null === $address ? null : Target::tryParse($address->target);

        if (null === $target || !$target->isPage()) {
            return null;
        }

        $data = $this->editMode->active() ? $this->reader->draft($target->id) ?? $this->reader->published($target->id) : $this->reader->published($target->id);

        if (null === $data) {
            return null;
        }

        $content = array_find($this->languages->contentLanguages($language->code), static fn(string $code): bool => isset($data->translations[$code]));

        if (null === $content) {
            return null;
        }

        return new ResolvedPage($target->id, $data, $language->code, $data->translations[$content], $language->path($path), $content);
    }
}
