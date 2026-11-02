<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Site;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Action\Journal;
use Frootbox\Core\Action\Schema\Description;
use Frootbox\Core\Action\Schema\Pattern;

/**
 * Changes one field of the website in its draft, e.g. while editing on
 * the website (decision 0128).
 *
 * @implements Action<SiteFieldUpdated>
 */
#[AsAction('site.update-field', 'Changes one field of the website, e.g. the address in the footer, in the draft of one language; fails with 409 if the field no longer holds "previous"')]
final readonly class UpdateSiteField implements Action
{
    public function __construct(
        #[Description('Language of the value, e.g. "de"')]
        public string $language,
        #[Description('Path of the field as site.show lists it, e.g. "address" or "links/0/text" for a field of the first entry of a list')]
        #[Pattern('^[a-zA-Z][a-zA-Z0-9]*(/(0|[1-9][0-9]*)/[a-zA-Z][a-zA-Z0-9]*)*$')]
        public string $path,
        #[Description('The new value as the type of the field stores it; null removes it, so the value of the first language shows')]
        public mixed $value = null,
        #[Description('The value as it was read; if the field holds something else now, nothing is saved')]
        public mixed $previous = null,
    ) {}

    public function handle(SiteEditor $editor, Journal $journal): SiteFieldUpdated
    {
        $updated = $editor->updateField('site.update-field', $this->language, $this->path, $this->value, $this->previous);

        return new SiteFieldUpdated($updated->language, $updated->path, $updated->value, $updated->html, $journal->currentEntryId(), $updated->parts);
    }
}
