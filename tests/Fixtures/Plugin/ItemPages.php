<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Fixtures\Plugin;

use Frootbox\Core\Revision\Revisions;
use Frootbox\Core\Revision\RevisionStatus;
use Frootbox\Core\Url\SubpageSource;

/**
 * Items at <base page>/<slug> below the page with the block Items.
 */
final readonly class ItemPages implements SubpageSource
{
    public const string TYPE = 'acme/plugin/item';

    public function __construct(private Revisions $revisions) {}

    public function type(): string
    {
        return self::TYPE;
    }

    public function blocks(): array
    {
        return ['acme/plugin/Items'];
    }

    public function paths(): iterable
    {
        foreach ($this->revisions->fetch(['where' => ['type' => self::TYPE, 'status' => RevisionStatus::Published]]) as $revision) {
            $paths = [];

            foreach (\is_array($revision->data['slugs'] ?? null) ? $revision->data['slugs'] : [] as $language => $slug) {
                if (\is_string($language) && \is_string($slug)) {
                    $paths[$language] = $slug;
                }
            }

            yield $revision->entityId => $paths;
        }
    }

    public function title(string $id, string $language): ?string
    {
        $titles = $this->revisions->published(self::TYPE, $id)->data['titles'] ?? [];
        $title = \is_array($titles) ? $titles[$language] ?? null : null;

        return \is_string($title) ? $title : null;
    }
}
