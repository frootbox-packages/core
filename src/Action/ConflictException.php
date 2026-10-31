<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Action;

/**
 * The data changed since the caller read it, e.g. a field that another
 * editor saved meanwhile (409). Reading again and retrying helps.
 */
final class ConflictException extends ActionException
{
    public static function field(): self
    {
        return new self('Someone changed the field meanwhile. Load the page again to see it.');
    }
}
