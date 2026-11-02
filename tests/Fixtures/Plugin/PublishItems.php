<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Fixtures\Plugin;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Action\Schema\ListOf;
use Frootbox\Core\Revision\RevisionStore;
use Frootbox\Core\Url\AddressSync;

/**
 * @implements Action<null>
 */
#[AsAction('acme/plugin/item.publish', 'Publishes items')]
final readonly class PublishItems implements Action
{
    /**
     * @param list<string> $ids
     */
    public function __construct(
        #[ListOf('string')]
        public array $ids,
    ) {}

    public function handle(RevisionStore $store, AddressSync $addresses): null
    {
        foreach ($this->ids as $id) {
            $store->publish(ItemPages::TYPE, $id);
        }

        // The addresses follow, like after page.publish (decision 0219).
        $addresses->sync();

        return null;
    }
}
