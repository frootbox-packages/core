<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Fixtures\Notes;

enum NoteStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
}
