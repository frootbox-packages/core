<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Navigation;

/**
 * A navigation with its draft and its published items by language, the
 * result of navigation.show and of the actions that change one.
 */
final readonly class NavigationDetails
{
    /**
     * @param array<string, list<array<string, mixed>>>|null $draft     Items by language
     * @param array<string, list<array<string, mixed>>>      $published Items by language
     */
    public function __construct(
        public string $name,
        public string $title,
        public int $depth,
        public NavigationStatus $status,
        public ?int $version,
        public ?array $draft,
        public array $published,
    ) {}
}
