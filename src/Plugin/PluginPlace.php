<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Plugin;

/**
 * The space of a plugin a page shows (decision 0232), part of the page:
 *
 *     "plugin": {"type": "frootbox/jobs/job", "space": "default", "details": true, "categories": false}
 */
final readonly class PluginPlace
{
    public function __construct(
        public string $type,
        public string $space = Space::DEFAULT,
        /** Pages of their own for the objects */
        public bool $details = true,
        /** Pages of their own for the categories (decision 0243) */
        public bool $categories = false,
    ) {}

    /**
     * The place from a snapshot of a page, null for none.
     */
    public static function fromArray(mixed $data): ?self
    {
        if (!\is_array($data) || !\is_string($data['type'] ?? null)) {
            return null;
        }

        $space = $data['space'] ?? null;
        $details = $data['details'] ?? null;

        return new self($data['type'], \is_string($space) && '' !== $space ? $space : Space::DEFAULT, \is_bool($details) ? $details : true, true === ($data['categories'] ?? false));
    }

    /**
     * @return array{type: string, space: string, details: bool, categories: bool}
     */
    public function toArray(): array
    {
        return ['type' => $this->type, 'space' => $this->space, 'details' => $this->details, 'categories' => $this->categories];
    }

    public function is(string $type, string $space): bool
    {
        return $this->type === $type && $this->space === $space;
    }
}
