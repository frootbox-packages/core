<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Page;

use DateTimeImmutable;
use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Action\Schema\Description;
use Frootbox\Core\Action\Schema\Pattern;
use Frootbox\Database\Ulid;

/**
 * @implements Action<PageDetails>
 */
#[AsAction('page.schedule', 'Plans when the draft of a page is published and when the page goes offline')]
final readonly class SchedulePage implements Action
{
    public function __construct(
        #[Pattern(Ulid::PATTERN)]
        public string $id,
        #[Description('When the draft is published, e.g. "2026-12-01T08:00:00+01:00"; left out or null for no time')]
        public ?DateTimeImmutable $publishAt = null,
        #[Description('When the page and its subpages go offline; left out or null for no time')]
        public ?DateTimeImmutable $unpublishAt = null,
    ) {}

    public function handle(PageEditor $editor): PageDetails
    {
        return $editor->schedule('page.schedule', $this->id, $this->publishAt, $this->unpublishAt);
    }
}
