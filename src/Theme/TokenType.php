<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Theme;

/**
 * What a token holds that editors may change (decision 0127).
 */
enum TokenType: string
{
    /** A colour as hex, e.g. "#8a4b2a". */
    case Color = 'color';

    /** The key of a font from the list of the theme, e.g. "serif". */
    case Font = 'font';

    /** A number with its unit, e.g. "14px". */
    case Length = 'length';

    /** The key of one of the options the theme names. */
    case Choice = 'choice';
}
