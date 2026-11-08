<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Settings;

/**
 * The settings of one extension.
 */
final readonly class SettingsDetails
{
    /**
     * @param list<SettingDetails> $fields
     */
    public function __construct(
        public string $package,
        public string $title,
        public string $description,
        public array $fields,
        /** Whether settings.test can check them against their service (decision 0353) */
        public bool $testable = false,
    ) {}
}
