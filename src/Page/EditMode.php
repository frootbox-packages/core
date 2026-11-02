<?php

/*
 * SPDX-License-Identifier: MIT
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

    private bool $site = false;

    private bool $anchors = false;

    /**
     * @param bool $site    Whether the fields of the website are marked as well, only with $marking (decision 0128)
     * @param bool $anchors Light marks for comments in a preview, only without $marking (decision 0156)
     */
    public function set(bool $active, bool $marking = false, bool $site = false, bool $anchors = false): void
    {
        $this->active = $active;
        $this->marking = $active && $marking;
        $this->site = $this->marking && $site;
        $this->anchors = $active && !$this->marking && $anchors;
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

    /**
     * Whether blocks and fields get light marks to comment on them, in a
     * preview: the page as visitors see it, plus attributes (decision 0156).
     */
    public function anchors(): bool
    {
        return $this->anchors;
    }

    /**
     * Whether the fields of the website get their markup for editing.
     */
    public function markingSite(): bool
    {
        return $this->site;
    }
}
