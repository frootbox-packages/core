<?php

/*
 * SPDX-License-Identifier: MIT
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
#[AsAction('preview.create', 'Creates a link that shows the whole website in its current draft to someone without an account, read only, and answers with its address')]
final readonly class CreatePreview implements Action
{
    public function __construct(
        #[Description('Who the link is for, e.g. "for Ms Miller"')]
        #[Length(max: 200)]
        public ?string $note = null,
        #[Description('When the link stops working, e.g. "2026-12-01T00:00:00+01:00"; left out for 14 days from now, at most 90 days')]
        public ?DateTimeImmutable $expiresAt = null,
        #[Description('The page the link opens; left out for the start page')]
        #[Pattern(Ulid::PATTERN)]
        public ?string $pageId = null,
        #[Description('Whether the link opens the design page /_design with every element of the website, e.g. for an agency; then without pageId')]
        public bool $design = false,
    ) {}

    public function handle(PreviewEditor $editor): PreviewSummary
    {
        return $editor->create('preview.create', $this->note, $this->expiresAt, $this->pageId, $this->design);
    }
}
