<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Fixtures\Plugin;

use Frootbox\Core\Plugin\Offer;
use Frootbox\Core\Plugin\Plugin;
use Frootbox\Core\Plugin\Space;
use Frootbox\Core\Revision\Revision;
use Frootbox\Core\Revision\Revisions;
use Frootbox\Core\Revision\RevisionStatus;

/**
 * Items at <page of the space>/<slug> (decisions 0219, 0232), shown by
 * the block Items.
 */
final readonly class ItemPlugin implements Plugin
{
    public const string TYPE = 'acme/plugin/item';

    public function __construct(private Revisions $revisions) {}

    public function type(): string
    {
        return self::TYPE;
    }

    public function label(): string
    {
        return 'Items';
    }

    public function block(): string
    {
        return 'acme/plugin/Items';
    }

    public function details(): Offer
    {
        return Offer::Optional;
    }

    public function categories(): Offer
    {
        return Offer::Optional;
    }

    public function spaces(): bool
    {
        return true;
    }

    public function paths(string $space): iterable
    {
        foreach ($this->revisions->fetch(['where' => ['type' => self::TYPE, 'status' => RevisionStatus::Published]]) as $revision) {
            if (self::space($revision) !== $space) {
                continue;
            }

            $paths = [];

            foreach (\is_array($revision->data['slugs'] ?? null) ? $revision->data['slugs'] : [] as $language => $slug) {
                if (\is_string($language) && \is_string($slug)) {
                    $paths[$language] = $slug;
                }
            }

            yield $revision->entityId => $paths;
        }
    }

    public function count(string $space): int
    {
        $ids = [];

        foreach ($this->revisions->fetch(['where' => ['type' => self::TYPE]]) as $revision) {
            if (self::space($revision) === $space) {
                $ids[$revision->entityId] = true;
            }
        }

        return \count($ids);
    }

    public function title(string $id, string $language): ?string
    {
        $titles = $this->revisions->published(self::TYPE, $id)->data['titles'] ?? [];
        $title = \is_array($titles) ? $titles[$language] ?? null : null;

        return \is_string($title) ? $title : null;
    }

    public function description(string $id, string $language): ?string
    {
        return null;
    }

    public function image(string $id, string $language): ?array
    {
        return null;
    }

    private static function space(Revision $revision): string
    {
        $space = $revision->data['space'] ?? null;

        return \is_string($space) ? $space : Space::DEFAULT;
    }
}
