<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Navigation;

enum NavigationStatus: string
{
    /** Declared by the theme, without items so far. */
    case Empty = 'empty';

    /** Only a draft, never published. */
    case Draft = 'draft';

    /** Published, without a draft. */
    case Published = 'published';

    /** Published, with a draft of changes. */
    case Changed = 'changed';
}
