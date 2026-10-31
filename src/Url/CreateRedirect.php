<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Url;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Action\Schema\Description;

/**
 * @implements Action<Redirect>
 */
#[AsAction('redirect.create', 'Redirects a path, e.g. an old URL, to a page or another URL')]
final readonly class CreateRedirect implements Action
{
    public function __construct(
        #[Description('The path of the request without query, e.g. "/old-page.html"')]
        public string $path,
        #[Description('The page to go to, e.g. "page:01J…"; it follows the page when it moves')]
        public ?string $target = null,
        #[Description('A URL or path to go to instead of a page, e.g. "https://example.com"')]
        public ?string $location = null,
        #[Description('301 if true, otherwise 302')]
        public bool $permanent = true,
    ) {}

    public function handle(RedirectEditor $editor): Redirect
    {
        return $editor->save('redirect.create', null, $this->path, $this->target, $this->location, $this->permanent);
    }
}
