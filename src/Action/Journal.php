<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Action;

/**
 * Keeps track of the actions that are running right now.
 *
 * An action stays undoable as long as every change goes through the
 * repositories and every nested action is undoable itself (or registered
 * a compensation). A write past the repositories, noticed by the
 * WriteGuard, makes the whole action not undoable.
 */
final class Journal
{
    /** @var list<array{entry: int, undoable: bool, compensated: bool}> */
    private array $frames = [];

    private bool $undoable = true;

    private int $trusted = 0;

    private ?int $lastUndoable = null;

    public function isRecording(): bool
    {
        return [] !== $this->frames;
    }

    /**
     * The log entry of the innermost running action.
     */
    public function currentEntryId(): ?int
    {
        return [] === $this->frames ? null : $this->frames[array_key_last($this->frames)]['entry'];
    }

    /**
     * The log entry of the outermost running action. Changes belong to it.
     */
    public function rootEntryId(): ?int
    {
        return $this->frames[0]['entry'] ?? null;
    }

    /**
     * The log entry of the last outermost action that ended, if it can be
     * undone, e.g. for a button "Undo" right after it (decision 0176).
     */
    public function lastUndoableEntryId(): ?int
    {
        return $this->lastUndoable;
    }

    public function begin(int $entryId, bool $undoable): void
    {
        if ([] === $this->frames) {
            $this->undoable = true;
            $this->lastUndoable = null;
        }

        $this->frames[] = ['entry' => $entryId, 'undoable' => $undoable, 'compensated' => false];
    }

    /**
     * Ends the innermost action.
     *
     * @return bool|null For the outermost action whether it can be undone, null for a nested one
     */
    public function end(): ?bool
    {
        $frame = array_pop($this->frames);

        if (null !== $frame && !$frame['undoable'] && !$frame['compensated']) {
            $this->undoable = false;
        }

        if ([] !== $this->frames) {
            return null;
        }

        $this->lastUndoable = null !== $frame && $this->undoable ? $frame['entry'] : null;

        return $this->undoable;
    }

    /**
     * Ends the innermost action after an error.
     */
    public function abort(): void
    {
        array_pop($this->frames);
    }

    /**
     * Makes the running action not undoable, e.g. because it changed a
     * sensitive column.
     */
    public function preventUndo(): void
    {
        if ([] !== $this->frames) {
            $this->undoable = false;
        }
    }

    public function markCompensated(): void
    {
        $last = array_key_last($this->frames);

        if (null !== $last) {
            $this->frames[$last]['compensated'] = true;
        }
    }

    /**
     * Called by the WriteGuard for every write to the database.
     */
    public function noticeWrite(): void
    {
        if ([] !== $this->frames && 0 === $this->trusted) {
            $this->undoable = false;
        }
    }

    /**
     * Runs writes that are recorded or bookkeeping, so the WriteGuard
     * ignores them.
     *
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    public function trusted(callable $callback): mixed
    {
        ++$this->trusted;

        try {
            return $callback();
        } finally {
            --$this->trusted;
        }
    }
}
