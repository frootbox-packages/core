<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Fixtures\Plugin;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Action\Schema\ListOf;
use Frootbox\Core\Category\ObjectCategories;
use Frootbox\Core\ObjectField\ObjectFields;
use Frootbox\Core\Revision\RevisionStore;
use Frootbox\Core\Tag\ObjectTags;
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

    public function handle(RevisionStore $store, AddressSync $addresses, ObjectCategories $categories, ObjectTags $tags, ObjectFields $fields): null
    {
        foreach ($this->ids as $id) {
            $published = $store->publish(ItemPlugin::TYPE, $id);
            // The categories of the published state, for lists (decision 0233).
            $chosen = $published->data['categories'] ?? [];
            $categories->sync(ItemPlugin::TYPE, $id, \is_array($chosen) ? array_values(array_filter($chosen, \is_string(...))) : []);
            $tagged = $published->data['tags'] ?? [];
            $tags->sync(ItemPlugin::TYPE, $id, \is_array($tagged) ? array_values(array_filter($tagged, \is_string(...))) : []);
            $values = $published->data['fields'] ?? [];
            $fields->sync(ItemPlugin::TYPE, $id, \is_array($values) ? $values : []);
        }

        // The addresses follow, like after page.publish (decision 0219).
        $addresses->sync();

        return null;
    }
}
