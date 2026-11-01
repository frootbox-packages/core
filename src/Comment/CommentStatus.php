<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Comment;

/**
 * Whether a thread of comments still needs an answer (decision 0156).
 */
enum CommentStatus: string
{
    case Open = 'open';
    case Done = 'done';
}
