<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Page;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Action\Schema\Description;
use Frootbox\Core\Action\Schema\Pattern;
use Frootbox\Database\Ulid;

/**
 * @implements Action<PageDetails>
 */
#[AsAction('page.update', 'Changes layout, titles, slugs or content of a page in its draft')]
final readonly class UpdatePage implements Action
{
    /**
     * @param array<mixed>|null $translations
     */
    public function __construct(
        #[Pattern(Ulid::PATTERN)]
        public string $id,
        #[Description('Changes by language; left out parts stay as they are, a language set to null is removed: {"de": {"title": "Neu"}, "en": null}')]
        public ?array $translations = null,
        #[Description('Full name of the layout, left out to keep it')]
        public ?string $layout = null,
        #[Description('Variant of the layout, left out to keep it, "Default" for the default')]
        public ?string $variant = null,
    ) {}

    public function handle(PageEditor $editor): PageDetails
    {
        $current = $editor->current($this->id);
        $translations = null === $this->translations ? $current->translations : $editor->translations('page.update', $this->translations, $current->translations);

        return $editor->saveDraft('page.update', $this->id, $current->with(layout: $this->layout, variant: $this->variant, translations: $translations));
    }
}
