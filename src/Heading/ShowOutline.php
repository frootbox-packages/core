<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Heading;

use Frootbox\Core\Action\AsQuery;
use Frootbox\Core\Action\InvalidInputException;
use Frootbox\Core\Action\Query;
use Frootbox\Core\Action\Schema\Length;
use Frootbox\Core\Action\Schema\Pattern;
use Frootbox\Core\Auth\Scope;
use Frootbox\Core\Auth\Scoped;
use Frootbox\Core\Language\Languages;
use Frootbox\Database\Ulid;

/**
 * The outline of a page: its headings in order with the level each gets
 * and what to know about it (decision 0129). For the panel „Gliederung“,
 * the REST API and later the AI.
 *
 * @implements Query<list<OutlineHeading>>
 */
#[AsQuery('page.outline', 'Returns the headings of a page in order with the level each gets, from the draft or the published page', permission: 'page.show')]
final readonly class ShowOutline implements Query, Scoped
{
    /**
     * @param string|null $language By default the first language
     * @param bool        $draft    The draft as the editing mode shows it, otherwise the published page
     */
    public function __construct(
        #[Pattern(Ulid::PATTERN)]
        public string $id,
        #[Length(max: 20)]
        public ?string $language = null,
        public bool $draft = true,
    ) {}

    /**
     * @return list<Scope>
     */
    public function scopes(): array
    {
        return [Scope::page($this->id)];
    }

    /**
     * @return list<OutlineHeading>
     */
    public function handle(OutlineReader $reader, Languages $languages): array
    {
        $language = $this->language ?? $languages->default()->code;

        if (!$languages->has($language)) {
            throw new InvalidInputException('page.outline', ['/language' => 'is not a language of the website.']);
        }

        return $reader->read($this->id, $language, $this->draft)
            ?? throw new InvalidInputException('page.outline', ['/language' => 'The page has no translation in this language.']);
    }
}
