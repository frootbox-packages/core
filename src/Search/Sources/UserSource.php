<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Search\Sources;

use Frootbox\Core\Search\AsSearchSource;
use Frootbox\Core\Search\SearchEntry;
use Frootbox\Core\Search\SearchSource;
use Frootbox\Core\User\Users;

/**
 * Users by name and email, only for those who may list them (decisions
 * 0214, 0215).
 */
#[AsSearchSource('user', 'Users')]
final readonly class UserSource implements SearchSource
{
    public function __construct(private Users $users) {}

    public function changed(string $table, array $row): array
    {
        return Users::TABLE === $table && isset($row['id']) && \is_scalar($row['id']) ? [(string) $row['id']] : [];
    }

    public function items(): iterable
    {
        foreach ($this->users->fetch(['order' => 'id']) as $user) {
            yield (string) $user->id;
        }
    }

    public function entries(array $items): iterable
    {
        foreach ($items as $id) {
            $user = ctype_digit($id) ? $this->users->fetchById((int) $id) : null;

            if (null !== $user) {
                yield new SearchEntry($id, $user->name, $user->email ?? '', 'user.list');
            }
        }
    }
}
