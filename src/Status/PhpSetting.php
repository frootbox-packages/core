<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Status;

/**
 * One directive of the php.ini, as it applies here and as the server sets it.
 */
final readonly class PhpSetting
{
    /**
     * Whether something below the php.ini changed it, e.g. a .htaccess or ini_set().
     */
    public bool $changed;

    public function __construct(
        public string $name,
        public ?string $value,
        public ?string $global,
    ) {
        $this->changed = $value !== $global;
    }
}
