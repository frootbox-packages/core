<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Action;

use Frootbox\Core\Support\Message;

/**
 * The data changed since the caller read it, e.g. a field that another
 * editor saved meanwhile (409). Reading again and retrying helps.
 */
final class ConflictException extends ActionException
{
    /** For an upload in parts: the bytes the server has, to go on from there (decision 0177). */
    public ?int $received = null;

    public static function upload(int $received): self
    {
        $error = new self(new Message('This part does not start where the upload stands. Go on at byte {received}.', ['received' => $received]));
        $error->received = $received;

        return $error;
    }

    public static function field(): self
    {
        return new self('Someone changed the field meanwhile. Load the page again to see it.');
    }
}
