<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Storage;

/**
 * The two stores of an installation (decision 0268).
 */
enum StoreRole: string
{
    /** Data nobody may load directly: originals, files from forms. Was storage/. */
    case Storage = 'storage';

    /** What browsers load directly: sizes and copies of public media. Was public/. */
    case Public = 'public';

    /** The variable in the .env. */
    public function variable(): string
    {
        return match ($this) {
            self::Storage => 'STORAGE_DSN',
            self::Public => 'PUBLIC_STORAGE_DSN',
        };
    }
}
