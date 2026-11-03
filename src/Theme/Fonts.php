<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Theme;

/**
 * Fonts of an extension (decision 0252):
 *
 *     $registry->add(Fonts::class, new Fonts(ImportedFonts::class));
 *
 * The source is loaded from the container when the themes are.
 */
final readonly class Fonts
{
    /**
     * @param class-string<FontSource> $source
     */
    public function __construct(public string $source) {}
}
