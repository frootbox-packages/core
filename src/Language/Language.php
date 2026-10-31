<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Language;

/**
 * A language of the website, e.g. "de" with the title "Deutsch".
 */
final readonly class Language
{
    public function __construct(
        public string $code,
        public string $title,
    ) {}
}
