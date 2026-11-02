<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Design;

/**
 * The design of the active theme: its tokens with type, whether there is
 * a draft and which version is published (decision 0127).
 */
final readonly class DesignDetails
{
    /**
     * @param list<TokenDetails> $tokens
     */
    public function __construct(
        public ?string $theme,
        public bool $draft,
        public ?int $version,
        public array $tokens,
    ) {}
}
