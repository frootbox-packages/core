<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Status;

/**
 * A loaded extension of PHP with its version and directives.
 */
final readonly class PhpExtension
{
    /**
     * @param list<PhpSetting> $settings by name
     */
    public function __construct(
        public string $name,
        public ?string $version,
        public array $settings,
    ) {}
}
