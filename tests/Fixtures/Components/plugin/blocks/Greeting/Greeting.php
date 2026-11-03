<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Fixtures\Plugin\Blocks;

use Frootbox\Core\Component\BlockContext;
use Frootbox\Core\Component\BlockController;
use Frootbox\Core\I18n\Translator;
use Frootbox\Core\View\Delivery;

/**
 * Lies outside the PSR-4 folders on purpose: the core loads the file
 * itself (decision 0219).
 */
final readonly class Greeting implements BlockController
{
    public function __construct(
        private Translator $translator,
        private Delivery $delivery,
    ) {}

    public function data(BlockContext $context): array
    {
        // Like the results of a search, never kept in the page cache.
        if ('secret' === $context->query('name')) {
            $this->delivery->withoutCache();
        }

        return [
            'greeting' => \sprintf('Hello %s in %s (%s)', $context->query('name') ?? 'world', $context->language, $this->translator->language()),
            'page' => (int) ($context->query('page') ?? 1),
            'target' => (string) $context->target,
            // The id of the block is not the controller's to change.
            'id' => 'taken',
        ];
    }
}
