<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Extension\Source;

use Frootbox\Core\Extension\ExtensionPackage;

/**
 * A place where extensions are found: the Composer packages of the
 * project, or the source of the workbench (decision 0254).
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
