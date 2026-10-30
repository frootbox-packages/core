<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Extension\Source;

use Frootbox\Core\Extension\ExtensionPackage;

/**
 * A place where extensions are found. Today there is only Composer.
 */
interface ExtensionSource
{
    /**
     * @return list<ExtensionPackage>
     */
    public function packages(): array;

    /**
     * Changes whenever the installed extensions change. Used to know when
     * the cached list is outdated.
     */
    public function fingerprint(): string;
}
