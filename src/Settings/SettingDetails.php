<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Settings;

/**
 * One setting with its value, for the backend and the REST API.
 */
final readonly class SettingDetails
{
    /**
     * @param array<string, mixed> $schema the JSON schema of the value
     */
    public function __construct(
        public string $name,
        public string $title,
        public string $description,
        /** "string", "int", "float" or "bool" */
        public string $type,
        public bool $nullable,
        public string|int|float|bool|null $default,
        /** The value in use; always null for a secret */
        public string|int|float|bool|null $value,
        /** A secret is stored encrypted and never shown again */
        public bool $secret,
        /** Whether a secret has a value */
        public bool $set,
        /** Set in config/extensions/…, so it cannot be changed here */
        public bool $locked,
        public array $schema,
    ) {}
}
