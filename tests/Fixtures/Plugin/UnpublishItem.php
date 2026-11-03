<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Fixtures\Plugin;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Category\ObjectCategories;
use Frootbox\Core\ObjectField\ObjectFields;
use Frootbox\Core\Revision\RevisionStore;
use Frootbox\Core\Tag\ObjectTags;
use Frootbox\Core\Url\AddressSync;

/**
 * @implements Action<null>
 */
#[AsAction('acme/plugin/item.unpublish', 'Takes an item offline')]
final readonly class UnpublishItem implements Action
{
    public function __construct(public string $id) {}

    public function handle(RevisionStore $store, AddressSync $addresses, ObjectCategories $categories, ObjectTags $tags, ObjectFields $fields): null
    {
        $store->archive(ItemPlugin::TYPE, $this->id);
        $categories->clear(ItemPlugin::TYPE, $this->id);
        $tags->clear(ItemPlugin::TYPE, $this->id);
        $fields->clear(ItemPlugin::TYPE, $this->id);
        $addresses->sync();

        return null;
    }
}
