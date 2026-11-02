<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Help;

/**
 * A page in the outline of the help.
 */
final readonly class HelpEntry
{
    /**
     * @param list<string> $about
     */
    public function __construct(
        public string $id,
        public string $title,
        public ?string $package,
        public array $about,
    ) {}
}
