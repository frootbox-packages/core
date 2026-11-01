<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Design;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;

/**
 * @implements Action<DesignDetails>
 */
#[AsAction('design.discard', 'Throws away the draft of the design')]
final readonly class DiscardDesign implements Action
{
    public function handle(DesignEditor $editor): DesignDetails
    {
        return $editor->discard('design.discard');
    }
}
