<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Media;

/**
 * A place that uses a medium, as media.usages gives it (decision 0146):
 * a page ("page", its id) or the fields of the website ("site", the
 * package of the theme), in one language. Draft and published say where
 * the medium is used: in the draft, in what visitors see, or in both.
 */
final readonly class MediumUsage
{
    public function __construct(
        public string $type,
        public string $id,
        public string $language,
        public string $title,
        public bool $draft,
        public bool $published,
    ) {}
}
