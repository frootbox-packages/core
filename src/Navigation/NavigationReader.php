<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Navigation;

use Frootbox\Core\Revision\Revisions;

/**
 * Reads navigations with their drafts, the way editors see them.
 */
final readonly class NavigationReader
{
    public const string TYPE = 'navigation';

    public function __construct(
        private Navigations $navigations,
        private Revisions $revisions,
    ) {}

    /**
     * @return array<string, list<array<string, mixed>>> Items by language
     */
    public function published(string $name): array
    {
        return array_map(static fn(Navigation $navigation): array => $navigation->items, $this->navigations->of($name));
    }

    /**
     * @return array<string, list<array<string, mixed>>>|null Items by language
     */
    public function draft(string $name): ?array
    {
        $draft = $this->revisions->draft(self::TYPE, $name);

        return null === $draft ? null : self::items($draft->data);
    }

    /**
     * The items as editors see them: the draft if there is one.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function current(string $name): array
    {
        return $this->draft($name) ?? $this->published($name);
    }

    public function details(Definition $definition): NavigationDetails
    {
        $draft = $this->draft($definition->name);
        $published = $this->published($definition->name);
        $version = $this->revisions->published(self::TYPE, $definition->name)?->version;

        $status = match (true) {
            null === $version && null === $draft => NavigationStatus::Empty,
            null === $version => NavigationStatus::Draft,
            null === $draft => NavigationStatus::Published,
            default => NavigationStatus::Changed,
        };

        return new NavigationDetails($definition->name, $definition->title, $definition->depth, $status, $version, $draft, $published);
    }

    /**
     * The items of a snapshot: {"items": {"de": [...]}}.
     *
     * @param array<mixed> $data
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public static function items(array $data): array
    {
        $items = [];

        foreach (\is_array($data['items'] ?? null) ? $data['items'] : [] as $language => $list) {
            $items[(string) $language] = self::list($list);
        }

        return $items;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function list(mixed $list): array
    {
        $items = [];

        foreach (\is_array($list) ? $list : [] as $item) {
            if (\is_array($item)) {
                $map = [];

                foreach ($item as $key => $value) {
                    $map[(string) $key] = $value;
                }

                $items[] = $map;
            }
        }

        return $items;
    }
}
