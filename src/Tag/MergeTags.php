<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tag;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Action\Schema\Description;
use Frootbox\Core\Action\Schema\Pattern;
use Frootbox\Database\Ulid;

/**
 * @implements Action<Tag>
 */
#[AsAction('tag.merge', 'Puts everything with one tag on another one, e.g. two spellings of a word')]
final readonly class MergeTags implements Action
{
    public function __construct(
        #[Pattern(Ulid::PATTERN)]
        #[Description('The tag that goes')]
        public string $id,
        #[Pattern(Ulid::PATTERN)]
        #[Description('The tag that stays')]
        public string $into,
    ) {}

    public function handle(TagEditor $editor): Tag
    {
        return $editor->merge('tag.merge', $this->id, $this->into);
    }
}
