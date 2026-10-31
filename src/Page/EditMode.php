<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Page;

/**
 * Whether the request shows the website in the editing mode (decision
 * 0119): pages, addresses and navigations from the drafts, and, if the
 * user may change the page, its fields and blocks marked for editing.
 * Set by the PageHandler for each request.
 */
final class EditMode
{
    /** Set by the backend; it counts only for users who may see pages. */
    public const string COOKIE = 'frootbox_edit';

    private bool $active = false;

    private bool $marking = false;

    public function set(bool $active, bool $marking = false): void
    {
        $this->active = $active;
        $this->marking = $active && $marking;
    }

    /**
     * Whether drafts are shown.
     */
    public function active(): bool
    {
        return $this->active;
    }

    /**
     * Whether fields and blocks get their markup for editing.
     */
    public function marking(): bool
    {
        return $this->marking;
    }
}
