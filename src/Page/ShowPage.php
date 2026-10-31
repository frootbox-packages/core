<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Page;

use Frootbox\Core\Action\AsQuery;
use Frootbox\Core\Action\Query;
use Frootbox\Core\Action\Schema\Pattern;
use Frootbox\Core\Auth\Scope;
use Frootbox\Core\Auth\Scoped;
use Frootbox\Core\Language\Languages;
use Frootbox\Core\Url\Target;
use Frootbox\Core\Url\Urls;
use Frootbox\Database\Ulid;

/**
 * @implements Query<PageDetails>
 */
#[AsQuery('page.show', 'Returns a page with its draft, its published state and where visitors find it')]
final readonly class ShowPage implements Query, Scoped
{
    public function __construct(
        #[Pattern(Ulid::PATTERN)]
        public string $id,
    ) {}

    /**
     * @return list<Scope>
     */
    public function scopes(): array
    {
        return [Scope::page($this->id)];
    }

    public function handle(PageReader $reader, Languages $languages, Urls $urls): PageDetails
    {
        $details = $reader->details($this->id);

        if (null === $details->published) {
            return $details;
        }

        $links = [];

        foreach ($languages->all() as $language) {
            $path = $urls->path(Target::PAGE . ':' . $this->id, $language->code);

            if (null !== $path) {
                $links[$language->code] = (null === $language->domain ? '' : $urls->base($language, null)) . $path;
            }
        }

        return $details->withUrls($links);
    }
}
