<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Fixtures\Notes;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Action\Schema\Email;
use Frootbox\Core\Action\Schema\Pattern;
use Frootbox\Core\Action\Schema\Sensitive;

/**
 * Public, with a secret.
 *
 * @implements Action<string>
 */
#[AsAction('note.subscribe', 'Subscribes to new notes', public: true)]
final readonly class Subscribe implements Action
{
    public function __construct(
        #[Email]
        public string $email,
        #[Sensitive]
        #[Pattern('^.{8,}$')]
        public string $password,
    ) {}

    public function handle(): string
    {
        return 'subscribed ' . $this->email;
    }
}
