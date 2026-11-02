<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Fixtures\Notes;

enum NoteStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
}
