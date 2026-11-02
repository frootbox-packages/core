<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Search\Sources;

use Frootbox\Core\Search\AsSearchSource;
use Frootbox\Core\Search\SearchEntry;
use Frootbox\Core\Search\SearchSource;
use Frootbox\Core\Url\Redirects;

/**
 * Redirects by their address and where they lead (decision 0214).
 */
#[AsSearchSource('redirect', 'Redirects')]
final readonly class RedirectSource implements SearchSource
{
    public function __construct(private Redirects $redirects) {}

    public function changed(string $table, array $row): array
    {
        return Redirects::TABLE === $table && isset($row['id']) && \is_scalar($row['id']) ? [(string) $row['id']] : [];
    }

    public function items(): iterable
    {
        foreach ($this->redirects->fetch(['order' => 'id']) as $redirect) {
            yield (string) $redirect->id;
        }
    }

    public function entries(array $items): iterable
    {
        foreach ($items as $id) {
            $redirect = ctype_digit($id) ? $this->redirects->fetchById((int) $id) : null;

            if (null !== $redirect) {
                yield new SearchEntry($id, $redirect->path, $redirect->location ?? '', 'redirect.list');
            }
        }
    }
}
