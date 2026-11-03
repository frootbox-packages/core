<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Url;

/**
 * A SubpageSource whose objects bring a description and an image for
 * search engines and sharing (decision 0223), e.g. the teaser and the
 * image of a news article.
 */
interface DescribedSubpages extends SubpageSource
{
    /**
     * One line, null or empty if the object has none in this language.
     */
    public function description(string $id, string $language): ?string;

    /**
     * The value of an image field, null without an image.
     *
     * @return array{media: string, alt?: string}|null
     */
    public function image(string $id, string $language): ?array;
}
