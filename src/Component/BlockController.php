<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Component;

/**
 * The logic of a block or layout (decisions 0074, 0219), in
 * blocks/<Name>/<Name>.php as <namespace of the extension>\Blocks\<Name>.
 * It is created by the container, so it gets its services in the
 * constructor, and gives the view further variables:
 *
 *     final readonly class ArticleList implements BlockController
 *     {
 *         public function __construct(private Runner $runner) {}
 *
 *         public function data(BlockContext $context): array
 *         {
 *             return ['articles' => $this->runner->ask(new ListArticles(page: (int) ($context->query('page') ?? 1)))];
 *         }
 *     }
 *
 * "id", "options" and the frame of the block stay as they are. Runs on
 * every render: on the website, in the editing mode, in previews and on
 * the design page, so it must cope with missing data.
 */
interface BlockController
{
    /**
     * @return array<string, mixed> Variables for the view
     */
    public function data(BlockContext $context): array;
}
