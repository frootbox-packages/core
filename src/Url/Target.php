<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Url;

use InvalidArgumentException;
use Stringable;

/**
 * Something with a URL, by type and id (decision 0105): "page:01J…" for
 * pages of the core, "acme/shop/product:123" for objects of extensions.
 * Links, redirects and later SEO data point to the target, never to a
 * URL, so they survive when the URL changes.
 */
final readonly class Target implements Stringable
{
    public const string PATTERN = '^(page|[a-z0-9][a-z0-9-]*/[a-z0-9][a-z0-9-]*/[a-z0-9][a-z0-9-]*):([A-Za-z0-9_-]{1,64})$';

    public const string PAGE = 'page';

    private function __construct(
        public string $type,
        public string $id,
    ) {}

    public static function page(string $id): self
    {
        return self::parse(self::PAGE . ':' . $id);
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function parse(string $target): self
    {
        return self::tryParse($target) ?? throw new InvalidArgumentException(\sprintf('"%s" is not a target. Use "page:<id>" or "<vendor>/<package>/<type>:<id>".', $target));
    }

    public static function tryParse(string $target): ?self
    {
        if (1 !== preg_match('#' . self::PATTERN . '#', $target, $match)) {
            return null;
        }

        return new self($match[1], $match[2]);
    }

    public function isPage(): bool
    {
        return self::PAGE === $this->type;
    }

    public function __toString(): string
    {
        return $this->type . ':' . $this->id;
    }
}
