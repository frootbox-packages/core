<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Url;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Action\Schema\Range;

/**
 * @implements Action<bool>
 */
#[AsAction('redirect.delete', 'Deletes a redirect')]
final readonly class DeleteRedirect implements Action
{
    public function __construct(
        #[Range(min: 1)]
        public int $id,
    ) {}

    public function handle(Redirects $redirects): bool
    {
        return $redirects->delete($redirects->getById($this->id)->id);
    }
}
