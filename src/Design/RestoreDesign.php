<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Design;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Action\Schema\Range;

/**
 * @implements Action<DesignDetails>
 */
#[AsAction('design.restore', 'Makes a new draft of the design from an earlier version')]
final readonly class RestoreDesign implements Action
{
    public function __construct(
        #[Range(min: 1)]
        public int $version,
    ) {}

    public function handle(DesignEditor $editor): DesignDetails
    {
        return $editor->restore('design.restore', $this->version);
    }
}
