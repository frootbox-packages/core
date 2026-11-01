<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Component;

/**
 * The groups of the block picker in their fixed order: those of the core,
 * then those of the extensions. Blocks without a group, or with one that
 * is not registered, stand under "Other" at the end.
 */
final readonly class BlockGroups
{
    /** The groups of the core with their English titles, in order. */
    public const array CORE = [
        'header' => 'Headers',
        'heading' => 'Headings',
        'text' => 'Texts',
        'media' => 'Images and media',
        'teaser' => 'Teasers',
        'callout' => 'Callouts',
        'list' => 'Lists',
        'form' => 'Forms',
        'structure' => 'Structure',
    ];

    /** @var array<string, BlockGroup> */
    private array $groups;

    /**
     * @param list<BlockGroup> $registered Of the extensions, in load order
     */
    public function __construct(array $registered = [])
    {
        $groups = [];

        foreach (self::CORE as $name => $title) {
            $groups[$name] = new BlockGroup($name, $title);
        }

        foreach ($registered as $group) {
            $groups[$group->name] ??= $group;
        }

        $this->groups = $groups;
    }

    public function has(string $name): bool
    {
        return isset($this->groups[$name]);
    }

    /**
     * @return list<BlockGroup>
     */
    public function all(): array
    {
        return array_values($this->groups);
    }
}
