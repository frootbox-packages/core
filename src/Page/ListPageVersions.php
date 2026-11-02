<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Page;

use Frootbox\Core\Action\AsQuery;
use Frootbox\Core\Action\Query;
use Frootbox\Core\Action\Schema\Pattern;
use Frootbox\Core\Auth\Scope;
use Frootbox\Core\Auth\Scoped;
use Frootbox\Core\Revision\Revisions;
use Frootbox\Core\Revision\RevisionStatus;
use Frootbox\Database\Ulid;

/**
 * @implements Query<list<PageVersion>>
 */
#[AsQuery('page.versions', 'Lists the published versions of a page, the newest first')]
final readonly class ListPageVersions implements Query, Scoped
{
    public function __construct(
        #[Pattern(Ulid::PATTERN)]
        public string $id,
    ) {}

    /**
     * @return list<Scope>
     */
    public function scopes(): array
    {
        return [Scope::page($this->id)];
    }

    /**
     * @return list<PageVersion>
     */
    public function handle(Revisions $revisions): array
    {
        $versions = [];

        foreach ($revisions->versions(PageReader::TYPE, $this->id) as $revision) {
            $versions[] = new PageVersion(
                $revision->version ?? 0,
                RevisionStatus::Published === $revision->status,
                $revision->publishedAt ?? $revision->createdAt,
                $revision->authorId,
                $revision->channel,
            );
        }

        return $versions;
    }
}
