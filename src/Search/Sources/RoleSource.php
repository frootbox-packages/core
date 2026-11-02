<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Search\Sources;

use Frootbox\Core\Search\AsSearchSource;
use Frootbox\Core\Search\SearchEntry;
use Frootbox\Core\Search\SearchSource;
use Frootbox\Core\User\Roles;

/**
 * Roles by title and name (decision 0214).
 */
#[AsSearchSource('role', 'Roles')]
final readonly class RoleSource implements SearchSource
{
    public function __construct(private Roles $roles) {}

    public function changed(string $table, array $row): array
    {
        return Roles::TABLE === $table && \is_string($row['name'] ?? null) ? [$row['name']] : [];
    }

    public function items(): iterable
    {
        foreach ($this->roles->fetch(['order' => 'id']) as $role) {
            yield $role->name;
        }
    }

    public function entries(array $items): iterable
    {
        foreach ($items as $name) {
            $role = $this->roles->findByName($name);

            if (null !== $role) {
                yield new SearchEntry($name, $role->title, $name, 'role.list');
            }
        }
    }
}
