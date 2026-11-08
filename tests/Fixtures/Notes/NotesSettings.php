<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Fixtures\Notes;

use Frootbox\Core\Action\Schema\Description;
use Frootbox\Core\Action\Schema\Length;
use Frootbox\Core\Action\Schema\Range;
use Frootbox\Core\Action\Schema\Sensitive;
use Frootbox\Core\Action\Schema\Title;
use Frootbox\Core\Settings\AsSettings;

#[AsSettings('Notes', 'How notes are sent', test: NotesConnection::class)]
final readonly class NotesSettings
{
    public function __construct(
        #[Title('Sender'), Description('The address notes come from')]
        #[Length(max: 100)]
        public string $sender = 'notes@example.test',
        #[Title('Notes per page'), Range(min: 1, max: 100)]
        public int $perPage = 20,
        public float $ratio = 1.5,
        public bool $archive = false,
        public ?string $footer = null,
        #[Title('API key'), Sensitive]
        public string $apiKey = '',
    ) {}
}
