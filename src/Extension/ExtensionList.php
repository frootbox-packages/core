<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Extension;

/**
 * The version of Frootbox and the installed extensions (decision 0274).
 */
final readonly class ExtensionList
{
    /**
     * @param string                 $version    of Frootbox, e.g. "0.1.0"
     * @param list<ExtensionSummary> $extensions packages first, then those of the website, each by name
     */
    public function __construct(
        public string $version,
        public array $extensions,
    ) {}
}
