<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Category;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Action\InvalidInputException;
use Frootbox\Core\Action\Schema\Description;
use Frootbox\Core\Support\Message;

/**
 * @implements Action<int>
 */
#[AsAction('category.apply-presets', 'Creates the categories the configuration or an extension sets out that do not exist yet; returns how many')]
final readonly class ApplyPresets implements Action
{
    public function __construct(
        #[Description('Only this group; left out for all')]
        public ?string $group = null,
    ) {}

    public function handle(CategoryEditor $editor, CategoryGroups $groups): int
    {
        if (null !== $this->group && null === $groups->get($this->group)) {
            throw new InvalidInputException('category.apply-presets', ['/group' => new Message('There is no group of categories "{group}".', ['group' => $this->group])]);
        }

        $created = 0;

        foreach ($groups->all() as $group) {
            if (null === $this->group || $group->key === $this->group) {
                $created += $editor->applyPresets($group);
            }
        }

        return $created;
    }
}
