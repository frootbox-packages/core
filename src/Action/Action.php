<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Action;

/**
 * A change of data. The only way to change data in Frootbox.
 *
 * The constructor describes the input, handle() does the work. Services
 * are passed to handle() by type:
 *
 *     #[AsAction('page.rename', 'Renames a page')]
 *     final readonly class RenamePage implements Action
 *     {
 *         public function __construct(
 *             public int $id,
 *             #[Length(min: 1, max: 200)]
 *             public string $title,
 *         ) {}
 *
 *         public function handle(Pages $pages): Page
 *         {
 *             return $pages->update($this->id, ['title' => $this->title]);
 *         }
 *     }
 *
 * Run it with Runner::run(), from outside with Runner::call().
 *
 * @template-covariant TResult = mixed
 */
interface Action {}
