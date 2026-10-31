<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\View;

use Frootbox\Core\Language\Language;
use Frootbox\Core\Language\Languages;
use Frootbox\Core\Url\Target;

/**
 * What is being delivered right now (decision 0105): the target, the
 * language of the visitor and the language its content comes from. They
 * differ where a page has no translation and falls back, e.g. English
 * around German content. Until a page is found both languages are the
 * default language.
 */
final class Delivery
{
    private ?Language $language = null;

    private ?Language $content = null;

    private ?Target $target = null;

    public function __construct(private readonly Languages $languages) {}

    public function set(Language $language, ?Language $content = null, ?Target $target = null): void
    {
        $this->language = $language;
        $this->content = $content ?? $language;
        $this->target = $target;
    }

    /**
     * The language of the visitor: frame, labels and links.
     */
    public function language(): Language
    {
        return $this->language ?? $this->languages->default();
    }

    /**
     * The language the content of the page is in.
     */
    public function contentLanguage(): Language
    {
        return $this->content ?? $this->language();
    }

    public function target(): ?Target
    {
        return $this->target;
    }

    public function isFallback(): bool
    {
        return $this->contentLanguage()->code !== $this->language()->code;
    }
}
