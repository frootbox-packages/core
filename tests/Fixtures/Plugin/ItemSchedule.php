<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Fixtures\Plugin;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Schedule\ScheduledType;

final readonly class ItemSchedule implements ScheduledType
{
    public function type(): string
    {
        return ItemPlugin::TYPE;
    }

    public function publish(array $ids): Action
    {
        return new PublishItems($ids);
    }

    public function unpublish(string $id): Action
    {
        return new UnpublishItem($id);
    }
}
