<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Preview;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Action\Schema\Pattern;
use Frootbox\Database\Ulid;

/**
 * @implements Action<PreviewSummary>
 */
#[AsAction('preview.revoke', 'Revokes a preview link at once; whoever has it sees a notice instead of the website')]
final readonly class RevokePreview implements Action
{
    public function __construct(
        #[Pattern(Ulid::PATTERN)]
        public string $id,
    ) {}

    public function handle(PreviewEditor $editor): PreviewSummary
    {
        return $editor->revoke('preview.revoke', $this->id);
    }
}
