<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Page;

enum PageStatus: string
{
    /** Only a draft, never published. */
    case Draft = 'draft';

    /** Published, without a draft. */
    case Published = 'published';

    /** Published, with a draft of changes. */
    case Changed = 'changed';
}
