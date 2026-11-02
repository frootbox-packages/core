<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Url;

use Frootbox\Core\Page\ResolvedPage;

/**
 * What a request path leads to (decision 0105): a page, a redirect, a
 * page that is gone (410) or nothing (404).
 */
final readonly class Resolution
{
    /**
     * @param list<string> $vary Request headers the answer depends on
     */
    private function __construct(
        public ?ResolvedPage $page = null,
        public ?string $location = null,
        public int $status = 404,
        public array $vary = [],
    ) {}

    public static function page(ResolvedPage $page): self
    {
        return new self($page, status: 200);
    }

    /**
     * @param list<string> $vary
     */
    public static function redirect(string $location, int $status = 301, array $vary = []): self
    {
        return new self(location: $location, status: $status, vary: $vary);
    }

    public static function gone(): self
    {
        return new self(status: 410);
    }

    public static function notFound(): self
    {
        return new self();
    }
}
