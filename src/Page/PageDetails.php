<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Page;

use DateTimeImmutable;

/**
 * A page with its draft and its published state, the result of page.show
 * and of the actions that change a page. publishAt and unpublishAt are
 * the planned times, see page.schedule.
 */
final readonly class PageDetails
{
    public function __construct(
        public string $id,
        public PageStatus $status,
        public ?int $version,
        public ?PageData $draft,
        public ?PageData $published,
        public ?DateTimeImmutable $publishAt = null,
        public ?DateTimeImmutable $unpublishAt = null,
        /**
         * Where visitors find the published page by language, e.g.
         * {"de": "/ueber-uns"}, a full URL for a language on its own
         * domain. Only page.show fills it.
         *
         * @var array<string, string>
         */
        public array $urls = [],
        /** The page's own lock, see page.lock */
        public Reach $locked = Reach::None,
        /** What applies, maybe from a page above (decision 0117) */
        public PageVisibility $visibility = new PageVisibility(),
    ) {}

    /**
     * @param array<string, string> $urls
     */
    public function withUrls(array $urls): self
    {
        return new self($this->id, $this->status, $this->version, $this->draft, $this->published, $this->publishAt, $this->unpublishAt, $urls, $this->locked, $this->visibility);
    }
}
