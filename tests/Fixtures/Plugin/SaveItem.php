<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Fixtures\Plugin;

use DateTimeImmutable;
use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Plugin\Space;
use Frootbox\Core\Revision\Revision;
use Frootbox\Core\Revision\RevisionStore;

/**
 * An item lives only in the table revisions: the published revision is
 * the published item. A real plugin writes it into its own table.
 *
 * @implements Action<Revision>
 */
#[AsAction('acme/plugin/item.save', 'Saves the draft of an item')]
final readonly class SaveItem implements Action
{
    /**
     * @param array<string, string> $titles by language
     * @param array<string, string> $slugs  by language
     */
    public function __construct(
        public string $id,
        public array $titles,
        public array $slugs,
        public ?DateTimeImmutable $publishAt = null,
        public string $space = Space::DEFAULT,
    ) {}

    public function handle(RevisionStore $store): Revision
    {
        $draft = $store->saveDraft(ItemPlugin::TYPE, $this->id, ['titles' => $this->titles, 'slugs' => $this->slugs, 'space' => $this->space]);
        $store->publishAt(ItemPlugin::TYPE, $this->id, $this->publishAt);

        return $draft;
    }
}
