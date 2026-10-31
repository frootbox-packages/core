<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Url;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Action\Schema\Description;
use Frootbox\Core\Action\Schema\Range;

/**
 * @implements Action<Redirect>
 */
#[AsAction('redirect.update', 'Changes the path or destination of a redirect')]
final readonly class UpdateRedirect implements Action
{
    public function __construct(
        #[Range(min: 1)]
        public int $id,
        #[Description('The path of the request without query, e.g. "/old-page.html"')]
        public string $path,
        #[Description('The page to go to, e.g. "page:01J…"')]
        public ?string $target = null,
        #[Description('A URL or path to go to instead of a page')]
        public ?string $location = null,
        #[Description('301 if true, otherwise 302')]
        public bool $permanent = true,
    ) {}

    public function handle(RedirectEditor $editor): Redirect
    {
        return $editor->save('redirect.update', $this->id, $this->path, $this->target, $this->location, $this->permanent);
    }
}
