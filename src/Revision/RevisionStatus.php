<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Revision;

enum RevisionStatus: string
{
    /** The work in progress, at most one per entity. */
    case Draft = 'draft';

    /** The state in the tables right now, at most one per entity. */
    case Published = 'published';

    /** An earlier published state that can be restored. */
    case Archived = 'archived';
}
