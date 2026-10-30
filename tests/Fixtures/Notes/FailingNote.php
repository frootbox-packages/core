<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Fixtures\Notes;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use RuntimeException;

/**
 * Creates a note and fails afterwards: nothing must remain.
 */
#[AsAction('note.fail', 'Creates a note and fails')]
final readonly class FailingNote implements Action
{
    public function __construct(public string $title) {}

    public function handle(Notes $notes): never
    {
        $notes->insert(['title' => $this->title]);

        throw new RuntimeException('Something broke');
    }
}
