<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Page;

use Frootbox\Core\Action\AsQuery;
use Frootbox\Core\Action\Query;
use Frootbox\Core\Action\Schema\Length;
use Frootbox\Core\Auth\Scope;
use Frootbox\Core\Auth\Scoped;

/**
 * The address the core would make from a title, for forms that show it
 * while someone types (decision 0134). The rules live only here, the
 * backend, the app and the AI ask for them.
 *
 * @implements Query<string>
 */
#[AsQuery('page.slug', 'Makes the address of a page from its title by the rules of a language, e.g. "Київ" in Ukrainian becomes "kyiv"; empty if the title has nothing to make one from', permission: 'page.tree')]
final readonly class SuggestSlug implements Query, Scoped
{
    /**
     * @param string $language a language code such as "de"; unknown ones get the general rules
     */
    public function __construct(
        #[Length(max: 255)]
        public string $title,
        #[Length(max: 35)]
        public string $language = '',
    ) {}

    /**
     * A role within a branch is enough, as for creating pages there.
     *
     * @return list<Scope>
     */
    public function scopes(): array
    {
        return [];
    }

    public function handle(): string
    {
        return Slug::fromTitle($this->title, $this->language);
    }
}
