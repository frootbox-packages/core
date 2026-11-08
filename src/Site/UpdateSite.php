<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Site;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Action\Schema\Description;

/**
 * @implements Action<SiteDetails>
 */
#[AsAction('site.update', 'Changes fields of the website, e.g. name and address, in the draft of one language; site.publish puts them online')]
final readonly class UpdateSite implements Action
{
    /**
     * @param array<mixed> $fields
     */
    public function __construct(
        #[Description('Language of the values, e.g. "de"')]
        public string $language,
        #[Description('Values by field name as site.show lists them, e.g. {"address": "Harbour Street 12"}; null or an empty value (empty text, rich text without text, image without media) removes a value, so the one of the first language shows')]
        public array $fields,
    ) {}

    public function handle(SiteEditor $editor): SiteDetails
    {
        return $editor->update('site.update', $this->language, $this->fields);
    }
}
