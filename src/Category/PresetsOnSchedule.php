<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Category;

use Frootbox\Core\Action\Context;
use Frootbox\Core\Action\Runner;
use Frootbox\Core\Schedule\ScheduleRan;

/**
 * Applies new presets of categories after each run of the scheduler
 * (decision 0239), so a preset in config/categories.yaml appears on a
 * live website without anyone pressing a button.
 */
final readonly class PresetsOnSchedule
{
    public function __construct(
        private CategoryGroups $groups,
        private CategoryEditor $editor,
        private Runner $runner,
    ) {}

    public function __invoke(ScheduleRan $event): void
    {
        foreach ($this->groups->all() as $group) {
            if ($this->editor->missingPresets($group) > 0) {
                $this->runner->run(new ApplyPresets(), Context::system());

                return;
            }
        }
    }
}
