<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Fixtures\Notes;

/**
 * Stands in for effects outside the database, such as sent mails.
 */
final class Outbox
{
    /** @var list<string> */
    public static array $messages = [];
}
