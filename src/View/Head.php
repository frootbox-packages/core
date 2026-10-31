<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\View;

/**
 * The <head> of a page before it is written. Listeners of BuildingHead
 * change the title or add tags, e.g. meta descriptions or fonts.
 */
final class Head
{
    /** @var list<string> */
    private array $tags = [];

    public function __construct(
        public string $language,
        public string $title,
        public ?string $canonical = null,
    ) {}

    /**
     * Adds a ready tag, e.g. '<meta name="robots" content="noindex">'.
     * Values in it must be escaped by the caller.
     */
    public function add(string $html): void
    {
        $this->tags[] = $html;
    }

    /**
     * @return list<string>
     */
    public function tags(): array
    {
        return $this->tags;
    }
}
