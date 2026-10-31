<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Field;

/**
 * One field from the YAML header or block.yaml, e.g.
 * "title: { type: headline, maxLength: 80 }".
 */
final readonly class Field
{
    /**
     * @param array<string, mixed> $settings Further keys of its type, e.g. maxLength
     * @param array<string, Field> $fields   Fields of each entry, for list fields
     */
    public function __construct(
        public string $name,
        public string $type,
        public string $label,
        public string $description = '',
        public bool $optional = false,
        public array $settings = [],
        public array $fields = [],
    ) {}

    public function setting(string $key, mixed $default = null): mixed
    {
        return $this->settings[$key] ?? $default;
    }

    public function intSetting(string $key, ?int $default = null): ?int
    {
        $value = $this->settings[$key] ?? null;

        return \is_int($value) ? $value : $default;
    }
}
