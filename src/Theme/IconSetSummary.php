<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Theme;

/**
 * One set of symbols in the result of icon.list (decision 0182).
 */
final readonly class IconSetSummary
{
    public function __construct(
        /** "theme", "media" or "<vendor>/<package>/<set>" */
        public string $name,
        /** In the language of the backend, e.g. "Eigene Symbole" */
        public string $label,
        /** The number of its symbols */
        public int $count,
    ) {}
}
