<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Component;

use Frootbox\Core\Url\Target;

/**
 * Where a block is rendered, for its controller (decision 0219).
 */
final readonly class BlockContext
{
    /**
     * @param array<string, mixed>  $options  The options of the variant with their defaults
     * @param array<string, string> $query    The query parameters the block declares, as far as given
     */
    public function __construct(
        public Instance $instance,
        public array $options,
        /** The language of the visitor, e.g. "en" */
        public string $language,
        /** The language of the content, differs in a fallback */
        public string $contentLanguage,
        /** What is delivered: the page, null e.g. on the design page */
        public ?Target $target = null,
        public array $query = [],
    ) {}

    /**
     * A query parameter the block declares, null if not given.
     */
    public function query(string $name): ?string
    {
        return $this->query[$name] ?? null;
    }
}
