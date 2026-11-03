<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tag;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Action\Schema\Description;

/**
 * @implements Action<Tag>
 */
#[AsAction('tag.create', 'Adds a tag to the common stock with a label per language')]
final readonly class CreateTag implements Action
{
    /**
     * @param array<mixed> $labels
     */
    public function __construct(
        #[Description('Labels by language: {"de": "Nachhaltigkeit", "en": "Sustainability"}')]
        public array $labels,
        #[Description('A fixed key of a-z, 0-9 and hyphens, e.g. "featured"; made from the first label if left out')]
        public ?string $key = null,
        #[Description('Only for selecting and switching, never shown to visitors')]
        public bool $internal = false,
    ) {}

    public function handle(TagEditor $editor): Tag
    {
        return $editor->create('tag.create', $this->labels, $this->key, $this->internal);
    }
}
