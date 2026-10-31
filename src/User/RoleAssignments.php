<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\User;

use Frootbox\Database\Repository;

/**
 * @extends Repository<RoleAssignment>
 */
final class RoleAssignments extends Repository
{
    public const string TABLE = 'role_assignments';

    public function find(int $userId, int $roleId, ?string $scope): ?RoleAssignment
    {
        return $this->fetchOne(['where' => ['userId' => $userId, 'roleId' => $roleId, 'scope' => $scope]]);
    }

    /**
     * The permission rules of all roles a user has everywhere, one list
     * per role.
     *
     * @return list<list<string>>
     */
    public function globalRules(int $userId): array
    {
        $rows = $this->connection->fetchFirstColumn(
            'SELECT r.permissions FROM ' . Roles::TABLE . ' r INNER JOIN ' . self::TABLE . ' a ON a.role_id = r.id WHERE a.user_id = ? AND a.scope IS NULL',
            [$userId],
        );
        $rules = [];

        foreach ($rows as $json) {
            $list = \is_string($json) ? json_decode($json, true) : null;
            $rules[] = \is_array($list) ? array_values(array_filter($list, \is_string(...))) : [];
        }

        return $rules;
    }

    /**
     * The highest level of the roles a user has everywhere, 0 without one.
     */
    public function globalLevel(int $userId): int
    {
        $level = $this->connection->fetchOne(
            'SELECT MAX(r.level) FROM ' . Roles::TABLE . ' r INNER JOIN ' . self::TABLE . ' a ON a.role_id = r.id WHERE a.user_id = ? AND a.scope IS NULL',
            [$userId],
        );

        return is_numeric($level) ? (int) $level : 0;
    }

    /**
     * The roles of a user by name, with their scope.
     *
     * @return list<array{role: string, scope: string|null}>
     */
    public function rolesOf(int $userId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT r.name, a.scope FROM ' . Roles::TABLE . ' r INNER JOIN ' . self::TABLE . ' a ON a.role_id = r.id WHERE a.user_id = ? ORDER BY r.name, a.scope',
            [$userId],
        );

        return array_map(static fn(array $row): array => [
            'role' => \is_string($row['name']) ? $row['name'] : '',
            'scope' => \is_string($row['scope']) ? $row['scope'] : null,
        ], $rows);
    }

    protected function table(): string
    {
        return self::TABLE;
    }

    protected function entity(): string
    {
        return RoleAssignment::class;
    }
}
