<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Fixtures\Plugin;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\ObjectField\ObjectFields;
use Frootbox\Core\Plugin\Space;
use Frootbox\Core\Revision\Revision;
use Frootbox\Core\Revision\Revisions;
use Frootbox\Core\Revision\RevisionStore;
use RuntimeException;

/**
 * Changes the further fields in the draft of an item, as the actions of
 * the modules do; what is left out stays (decision 0241).
 *
 * @implements Action<Revision>
 */
#[AsAction('acme/plugin/item.update', 'Changes the draft of an item')]
final readonly class UpdateItem implements Action
{
    /**
     * @param array<mixed>|null $fields
     */
    public function __construct(
        public string $id,
        public ?array $fields = null,
    ) {}

    public function handle(RevisionStore $store, Revisions $revisions, ObjectFields $objectFields): Revision
    {
        $revision = $revisions->draft(ItemPlugin::TYPE, $this->id) ?? $revisions->published(ItemPlugin::TYPE, $this->id) ?? throw new RuntimeException('There is no item ' . $this->id);
        $current = $revision->data;
        $before = \is_array($current['fields'] ?? null) ? $current['fields'] : [];
        $space = \is_string($current['space'] ?? null) ? $current['space'] : Space::DEFAULT;
        $current['fields'] = $objectFields->check('acme/plugin/item.update', ItemPlugin::TYPE, $before, $this->fields ?? [], space: $space);

        return $store->saveDraft(ItemPlugin::TYPE, $this->id, $current);
    }
}
