<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Page;

use Frootbox\Core\Url\Subpage;
use Frootbox\Core\Url\Target;

/**
 * A published page found for a path, in one language. Without a
 * translation in that language the content comes from a fallback
 * language (decision 0105).
 */
final readonly class ResolvedPage
{
    /**
     * @param string $language        The language of the URL and the visitor
     * @param string $contentLanguage The language of the translation shown
     * @param string $path            The path of the request, e.g. "/en/about-us"
     */
    public function __construct(
        public string $id,
        public PageData $data,
        public string $language,
        public TranslationData $translation,
        public string $path,
        public string $contentLanguage,
        /** An object of a plugin shown on this page as its base page (decision 0219) */
        public ?Subpage $subpage = null,
    ) {}

    public function withSubpage(Subpage $subpage): self
    {
        return new self($this->id, $this->data, $this->language, $this->translation, $this->path, $this->contentLanguage, $subpage);
    }

    public function isStart(): bool
    {
        return null === $this->data->parentId;
    }

    public function isFallback(): bool
    {
        return $this->language !== $this->contentLanguage;
    }

    public function target(): Target
    {
        return Target::page($this->id);
    }
}
