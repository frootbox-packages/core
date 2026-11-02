<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Design;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;

/**
 * @implements Action<DesignDetails>
 */
#[AsAction('design.publish', 'Publishes the draft of the design; visitors see it right away')]
final readonly class PublishDesign implements Action
{
    public function handle(DesignEditor $editor): DesignDetails
    {
        return $editor->publish('design.publish');
    }
}
