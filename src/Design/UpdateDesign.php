<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Design;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Action\Schema\Description;

/**
 * @implements Action<DesignDetails>
 */
#[AsAction('design.update', 'Changes tokens of the active theme in the draft of the design; design.publish puts it online')]
final readonly class UpdateDesign implements Action
{
    /**
     * @param array<mixed> $tokens
     */
    public function __construct(
        #[Description('Values by token name as design.show lists them, e.g. {"color-primary": "#8a4b2a"}; null goes back to the value of the theme')]
        public array $tokens,
    ) {}

    public function handle(DesignEditor $editor): DesignDetails
    {
        return $editor->update('design.update', $this->tokens);
    }
}
