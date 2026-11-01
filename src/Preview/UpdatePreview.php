<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Preview;

use DateTimeImmutable;
use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Action\Schema\Description;
use Frootbox\Core\Action\Schema\Length;
use Frootbox\Core\Action\Schema\Pattern;
use Frootbox\Database\Ulid;

/**
 * @implements Action<PreviewSummary>
 */
#[AsAction('preview.update', 'Changes the note or extends a preview link, also an expired one; a revoked link stays revoked')]
final readonly class UpdatePreview implements Action
{
    public function __construct(
        #[Pattern(Ulid::PATTERN)]
        public string $id,
        #[Description('A new note; left out to keep it, empty to remove it')]
        #[Length(max: 200)]
        public ?string $note = null,
        #[Description('A new end, at most 90 days from now; left out to keep it')]
        public ?DateTimeImmutable $expiresAt = null,
    ) {}

    public function handle(PreviewEditor $editor): PreviewSummary
    {
        return $editor->update('preview.update', $this->id, $this->note, $this->expiresAt, null !== $this->note);
    }
}
