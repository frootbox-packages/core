<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Preview;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Action\Journal;
use Frootbox\Core\Action\Schema\Length;
use Frootbox\Core\Action\Schema\Sensitive;
use Frootbox\Core\Http\Exception\HttpException;
use Frootbox\Database\Values;

/**
 * Checks a token on the first visit and notes when and how often the
 * link was opened, so the backend shows whether the customer looked.
 *
 * @implements Action<PreviewOpened>
 */
#[AsAction('preview.open', 'Checks the token of a preview link when it is opened and counts the visit', public: true, undoable: false)]
final readonly class OpenPreview implements Action
{
    public function __construct(
        #[Sensitive]
        #[Length(min: 1, max: 100)]
        public string $token,
    ) {}

    public function handle(PreviewEditor $editor, Connection $connection, Journal $journal): PreviewOpened
    {
        $now = new DateTimeImmutable();
        $link = $editor->valid($this->token, $now) ?? throw HttpException::previewExpired();
        $time = $now->format(Values::DATE_FORMAT);

        // Bookkeeping like a sign-in, not recorded for undoing.
        $journal->trusted(fn(): int|string => $connection->update(PreviewLinks::TABLE, [
            'first_opened_at' => null === $link->firstOpenedAt ? $time : $link->firstOpenedAt->format(Values::DATE_FORMAT),
            'last_opened_at' => $time,
            'opens' => $link->opens + 1,
        ], ['id' => $link->id]));

        return new PreviewOpened($link->id, $link->expiresAt);
    }
}
