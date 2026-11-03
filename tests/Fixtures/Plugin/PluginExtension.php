<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Fixtures\Plugin;

use Frootbox\Core\Category\CategoryGroup;
use Frootbox\Core\Extension\Extension;
use Frootbox\Core\Extension\Registry;

/**
 * A plugin as a third party would write it (decision 0219).
 */
final class PluginExtension implements Extension
{
    public function register(Registry $registry): void
    {
        $registry->action(SaveItem::class);
        $registry->action(PublishItems::class);
        $registry->action(UnpublishItem::class);
        $registry->action(UpdateItem::class);
        $registry->plugin(ItemPlugin::class);
        $registry->scheduled(ItemSchedule::class);
        $registry->add(CategoryGroup::class, new CategoryGroup('acme/plugin/topics', 'Topics', [ItemPlugin::TYPE]));
    }
}
