<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Action;

/**
 * An action cannot be undone.
 */
final class UndoException extends ActionException
{
    public static function notPossible(int $entryId, string $reason): self
    {
        return new self(\sprintf('Action #%d cannot be undone: %s', $entryId, $reason));
    }

    /**
     * @param list<string> $rows e.g. "pages #5"
     */
    public static function conflict(int $entryId, array $rows): self
    {
        return new self(\sprintf('Action #%d cannot be undone, the data has changed since: %s.', $entryId, implode(', ', $rows)));
    }
}
