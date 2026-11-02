<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Url;

/**
 * The addresses pages will have once published, from the tree with
 * drafts, for the editing mode (decision 0119). Worked out once per
 * request, never stored.
 */
final class DraftAddresses
{
    /** @var array<string, array<string, PagePath>>|null by language and path */
    private ?array $byPath = null;

    /** @var array<string, array<string, PagePath>> by page and language */
    private array $byPage = [];

    public function __construct(private readonly PagePaths $paths) {}

    /**
     * The page at a path in a language, e.g. "ueber-uns/team".
     */
    public function at(string $language, string $path): ?PagePath
    {
        return $this->read()[$language][$path] ?? null;
    }

    /**
     * The addresses of a page by language.
     *
     * @return array<string, PagePath>
     */
    public function of(string $pageId): array
    {
        $this->read();

        return $this->byPage[$pageId] ?? [];
    }

    public function clear(): void
    {
        $this->byPath = null;
        $this->byPage = [];
    }

    /**
     * @return array<string, array<string, PagePath>>
     */
    private function read(): array
    {
        if (null !== $this->byPath) {
            return $this->byPath;
        }

        $this->byPath = [];

        foreach ($this->paths->all(drafts: true) as $path) {
            $this->byPath[$path->language][$path->path] = $path;
            $this->byPage[$path->pageId][$path->language] = $path;
        }

        return $this->byPath;
    }
}
