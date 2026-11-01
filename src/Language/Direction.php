<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Language;

/**
 * The writing direction of a language (decision 0133).
 */
enum Direction: string
{
    case Ltr = 'ltr';
    case Rtl = 'rtl';

    /**
     * Languages written from right to left, by the first part of the code.
     */
    public const array RTL = ['ar', 'ckb', 'dv', 'fa', 'he', 'ps', 'sd', 'ug', 'ur', 'yi'];

    /**
     * The direction of a language code, e.g. "rtl" for "ar" or "fa-af".
     */
    public static function of(string $code): self
    {
        return \in_array(explode('-', strtolower($code))[0], self::RTL, true) ? self::Rtl : self::Ltr;
    }
}
