<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Fixtures\Plugin;

use DateTimeImmutable;
use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Category\ObjectCategories;
use Frootbox\Core\ObjectField\ObjectFields;
use Frootbox\Core\Plugin\Space;
use Frootbox\Core\Revision\Revision;
use Frootbox\Core\Revision\Revisions;
use Frootbox\Core\Revision\RevisionStore;
use Frootbox\Core\Tag\ObjectTags;

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
     * @param array<string, string> $slugs      by language
     * @param list<string>          $categories ids, kept in the draft until publishing (decision 0233)
     * @param list<string>          $tags       ids, keys or labels (decision 0239)
     * @param array<mixed>|null     $fields     further fields, merged into those of the draft (decision 0240)
     */
    public function __construct(
        public string $id,
        public array $titles,
        public array $slugs,
        public ?DateTimeImmutable $publishAt = null,
        public string $space = Space::DEFAULT,
        public array $categories = [],
        public array $tags = [],
        public ?array $fields = null,
    ) {}

    public function handle(RevisionStore $store, Revisions $revisions, ObjectCategories $categories, ObjectTags $tags, ObjectFields $objectFields): Revision
    {
        $before = ($revisions->draft(ItemPlugin::TYPE, $this->id) ?? $revisions->published(ItemPlugin::TYPE, $this->id))?->data['fields'] ?? [];
        $fields = $objectFields->check('acme/plugin/item.save', ItemPlugin::TYPE, \is_array($before) ? $before : [], $this->fields ?? [], space: $this->space);
        $chosen = $categories->check('acme/plugin/item.save', ItemPlugin::TYPE, $this->categories);
        $tagged = $tags->check('acme/plugin/item.save', ItemPlugin::TYPE, $this->space, $this->tags);
        $draft = $store->saveDraft(ItemPlugin::TYPE, $this->id, ['titles' => $this->titles, 'slugs' => $this->slugs, 'space' => $this->space, 'categories' => $chosen, 'tags' => $tagged, 'fields' => $fields]);
        $store->publishAt(ItemPlugin::TYPE, $this->id, $this->publishAt);

        return $draft;
    }
}
