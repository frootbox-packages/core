<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Page;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Action\InvalidInputException;
use Frootbox\Core\Action\Schema\Description;
use Frootbox\Core\Action\Schema\Pattern;
use Frootbox\Core\Auth\Scope;
use Frootbox\Core\Auth\Scoped;
use Frootbox\Core\Component\ComponentKind;
use Frootbox\Core\Component\Components;
use Frootbox\Core\Support\Message;
use Frootbox\Database\Ulid;

/**
 * @implements Action<PageDetails>
 */
#[AsAction('page.update', 'Changes layout, titles, slugs, content or the exclusions from searches of a page in its draft')]
final readonly class UpdatePage implements Action, Scoped
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
        #[Description('noindex for search engines and not in the sitemap: "page", "branch" for the page and all below it, "none"; left out to keep it')]
        public ?Reach $excludeFromSearchEngines = null,
        #[Description('Left out by the site search: "page", "branch" or "none"; left out to keep it')]
        public ?Reach $excludeFromSiteSearch = null,
    ) {}

    /**
     * @return list<Scope>
     */
    public function scopes(): array
    {
        return [Scope::page($this->id)];
    }

    public function handle(PageEditor $editor, Components $components): PageDetails
    {
        $current = $editor->current($this->id);

        // Pages keep a hidden layout, but none changes to it (decision 0162).
        if (null !== $this->layout && $this->layout !== $current->layout && null !== $components->layout($this->layout) && !$components->offers(ComponentKind::Layout, $this->layout)) {
            throw new InvalidInputException('page.update', ['/layout' => new Message('The theme does not offer the layout "{layout}" for pages.', ['layout' => $this->layout])]);
        }
        $translations = null === $this->translations ? $current->translations : $editor->translations('page.update', $this->translations, $current->translations);

        return $editor->saveDraft('page.update', $this->id, $current->with(
            layout: $this->layout,
            variant: $this->variant,
            translations: $translations,
            excludeFromSearchEngines: $this->excludeFromSearchEngines,
            excludeFromSiteSearch: $this->excludeFromSiteSearch,
        ));
    }
}
