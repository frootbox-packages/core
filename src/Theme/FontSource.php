<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Theme;

/**
 * Gives fonts of an extension (decision 0252), e.g. those imported from
 * Google Fonts. They join the fonts of the themes in the design panel,
 * in @font-face and in the preloads.
 */
interface FontSource
{
    /**
     * @return list<Font>
     */
    public function fonts(): array;
}
