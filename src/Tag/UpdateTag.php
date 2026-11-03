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
#[AsAction('tag.update', 'Changes the labels of a tag or whether it is internal; the key stays')]
final readonly class UpdateTag implements Action
{
    /**
     * @param array<mixed>|null $labels
     */
    public function __construct(
        #[Pattern(Ulid::PATTERN)]
        public string $id,
        #[Description('Labels by language; languages left out stay, an empty one goes')]
        public ?array $labels = null,
        #[Description('Only for selecting and switching, never shown to visitors')]
        public ?bool $internal = null,
    ) {}

    public function handle(TagEditor $editor): Tag
    {
        return $editor->update('tag.update', $this->id, $this->labels, $this->internal);
    }
}
