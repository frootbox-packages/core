<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Page;

/**
 * How far a setting of a page reaches (decision 0117): not at all, the
 * page only, or the page and all pages below it.
 */
enum Reach: string
{
    case None = 'none';
    case Page = 'page';
    case Branch = 'branch';

    /**
     * The page the setting comes from: the page itself if it has the
     * setting, else the nearest page above with Branch, null if none.
     *
     * @param array<string, self> $trail by id, from the start page down to the page
     */
    public static function source(array $trail): ?string
    {
        $own = array_key_last($trail);

        foreach (array_reverse($trail, true) as $id => $reach) {
            if (self::Branch === $reach || ($id === $own && self::Page === $reach)) {
                return $id;
            }
        }

        return null;
    }
}
