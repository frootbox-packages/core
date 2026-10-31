<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Page;

use Frootbox\Core\Language\Language;
use Frootbox\Core\Language\Languages;
use Frootbox\Core\Url\Addresses;
use Frootbox\Core\Url\Target;

/**
 * Finds the published page at a path in a language through its current
 * address (decision 0105).
 */
final readonly class PageResolver
{
    public function __construct(
        private Addresses $addresses,
        private PageReader $reader,
        private Languages $languages,
    ) {}

    /**
     * @param string $path The path without language prefix, e.g. "ueber-uns/team"
     */
    public function resolve(Language $language, string $path): ?ResolvedPage
    {
        $address = $this->addresses->at($language->code, trim($path, '/'));
        $target = null === $address ? null : Target::tryParse($address->target);

        if (null === $target || !$target->isPage()) {
            return null;
        }

        $data = $this->reader->published($target->id);

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
