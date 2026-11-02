<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Schedule;

use Frootbox\Core\Action\Action;

/**
 * How the scheduler publishes and takes offline the objects of a type
 * of a plugin, e.g. news articles (decisions 0094, 0219). Registered
 * with $registry->scheduled(ArticleSchedule::class). The actions run as
 * the system, like page.publish for pages.
 */
interface ScheduledType
{
    /**
     * The type in the table revisions, e.g. "acme/news/article".
     */
    public function type(): string;

    /**
     * The action that publishes the drafts of these objects.
     *
     * @param non-empty-list<string> $ids
     */
    public function publish(array $ids): Action;

    /**
     * The action that takes the object offline.
     */
    public function unpublish(string $id): Action;
}
