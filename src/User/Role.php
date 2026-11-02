<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\User;

use DateTimeImmutable;
use Frootbox\Core\Auth\PermissionRules;
use Frootbox\Database\Entity;

/**
 * A named bundle of permission rules, e.g. "editor" with ["page.*"]. Its
 * level ranks it: see Hierarchy.
 */
final readonly class Role extends Entity
{
    /**
     * @param list<string> $permissions
     */
    public function __construct(
        public int $id,
        public string $name,
        public string $title,
        public int $level,
        public array $permissions,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $updatedAt,
    ) {}

    public function rules(): PermissionRules
    {
        return new PermissionRules($this->permissions);
    }
}
