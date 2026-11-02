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
use Frootbox\Core\Action\Schema\Range;
use Frootbox\Core\Auth\Scope;
use Frootbox\Core\Auth\Scoped;
use Frootbox\Core\Component\ComponentKind;
use Frootbox\Core\Component\Components;
use Frootbox\Core\Support\Message;
use Frootbox\Database\Ulid;

/**
 * @implements Action<PageDetails>
 */
#[AsAction('page.create', 'Creates a page as a draft; page.publish puts it online')]
final readonly class CreatePage implements Action, Scoped
{
    /**
     * @param array<mixed> $translations
     */
    public function __construct(
        #[Description('Full name of the layout, e.g. "acme/website/Page"')]
        public string $layout,
        #[Description('Title, slug and content by language: {"de": {"title": "Über uns", "slug": "ueber-uns", "content": {"fields": {}, "areas": {"main": [{"block": "acme/website/Text", "fields": {}}]}}}}. The slug is made from the title if left out.')]
        public array $translations,
        #[Pattern(Ulid::PATTERN)]
        #[Description('The parent page; left out only for the start page')]
        public ?string $parentId = null,
        #[Range(min: 0)]
        #[Description('Place among the pages next to it, 0 for the first; left out for the last')]
        public ?int $position = null,
        #[Description('Variant of the layout, left out for its default')]
        public ?string $variant = null,
    ) {}

    /**
     * The parent page; a new top page only with the permission everywhere.
     *
     * @return list<Scope>|null
     */
    public function scopes(): ?array
    {
        return null === $this->parentId ? null : [Scope::page($this->parentId)];
    }

    public function handle(PageEditor $editor, Components $components): PageDetails
    {
        if (null !== $components->layout($this->layout) && !$components->offers(ComponentKind::Layout, $this->layout)) {
            throw new InvalidInputException('page.create', ['/layout' => new Message('The theme does not offer the layout "{layout}" for new pages.', ['layout' => $this->layout])]);
        }

        $id = Ulid::generate();
        $translations = $editor->translations('page.create', $this->translations);
        $data = new PageData($this->parentId, $editor->position($id, $this->parentId, $this->position), $this->layout, $this->variant, $translations);

        return $editor->saveDraft('page.create', $id, $data);
    }
}
