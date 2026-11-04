<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Media;

use Frootbox\Core\Action\AsQuery;
use Frootbox\Core\Action\InvalidInputException;
use Frootbox\Core\Action\Query;
use Frootbox\Core\Action\Schema\Description;
use Frootbox\Core\Language\Languages;
use Frootbox\Core\Support\Message;

/**
 * @implements Query<list<Credit>>
 */
#[AsQuery('media.credits', 'Returns the image credits: every published medium with a copyright that visitors see, with the pages that show it, e.g. for the imprint', public: true)]
final readonly class ListCredits implements Query
{
    public function __construct(
        #[Description('The language of the content, e.g. "de"; left out, the default language')]
        public ?string $language = null,
    ) {}

    /**
     * @return list<Credit>
     */
    public function handle(Credits $credits, Languages $languages): array
    {
        if (null !== $this->language && !$languages->has($this->language)) {
            throw new InvalidInputException('media.credits', ['/language' => new Message('There is no language "{language}".', ['language' => $this->language])]);
        }

        $language = null === $this->language ? $languages->default() : $languages->get($this->language);

        return $credits->list($language->code, $language->code);
    }
}
