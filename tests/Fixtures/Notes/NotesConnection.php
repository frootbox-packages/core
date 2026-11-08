<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Fixtures\Notes;

use Frootbox\Core\Settings\ConnectionFailed;
use Frootbox\Core\Settings\ConnectionTest;
use Frootbox\Core\Support\Message;
use LogicException;

/**
 * A test of the connection for the notes: without a key it fails, the key
 * "broken" throws an error no test expects, any other key works.
 */
final readonly class NotesConnection implements ConnectionTest
{
    public function __construct(private NotesSettings $settings) {}

    public function run(): Message
    {
        return match ($this->settings->apiKey) {
            '' => throw new ConnectionFailed(new Message('Store the {name} first.', ['name' => 'API key'])),
            'broken' => throw new LogicException('The notes service is down.'),
            default => new Message('Connected with {count} notes.', ['count' => 3]),
        };
    }
}
