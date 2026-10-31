<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Page;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Action\InvalidInputException;
use Frootbox\Core\Action\Schema\Pattern;
use Frootbox\Core\Action\Schema\Range;
use Frootbox\Core\Revision\Revisions;
use Frootbox\Database\Ulid;

/**
 * @implements Action<PageDetails>
 */
#[AsAction('page.restore', 'Makes a new draft from an earlier version of a page, also of a deleted one')]
final readonly class RestorePage implements Action
{
    public function __construct(
        #[Pattern(Ulid::PATTERN)]
        public string $id,
        #[Range(min: 1)]
        public int $version,
    ) {}

    public function handle(Revisions $revisions, PageEditor $editor): PageDetails
    {
        $old = $revisions->version(PageReader::TYPE, $this->id, $this->version)
            ?? throw new InvalidInputException('page.restore', ['/version' => \sprintf('The page %s has no version %d.', $this->id, $this->version)]);

        return $editor->saveDraft('page.restore', $this->id, PageData::fromArray($old->data));
    }
}
