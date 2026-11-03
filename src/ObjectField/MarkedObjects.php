<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\ObjectField;

/**
 * The objects whose further fields a page marked for editing, with
 * whether each has a draft (decision 0241). The editor bar publishes and
 * discards them along with the page.
 */
final class MarkedObjects
{
    /** @var array<string, array{type: string, id: string, label: string, title: string, draft: bool}> By target */
    private array $objects = [];

    /**
     * Before a page renders.
     */
    public function clear(): void
    {
        $this->objects = [];
    }

    public function has(string $type, string $id): bool
    {
        return isset($this->objects[$type . ':' . $id]);
    }

    /**
     * @param string $label The name of the type in English, e.g. "News"
     * @param string $title Of the object, empty without one
     */
    public function add(string $type, string $id, string $label, string $title, bool $draft): void
    {
        $this->objects[$type . ':' . $id] = ['type' => $type, 'id' => $id, 'label' => $label, 'title' => $title, 'draft' => $draft];
    }

    /**
     * @return list<array{type: string, id: string, label: string, title: string, draft: bool}>
     */
    public function all(): array
    {
        return array_values($this->objects);
    }
}
