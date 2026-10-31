<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\User;

use Frootbox\Core\Action\InvalidInputException;
use Frootbox\Core\Auth\PermissionRules;
use Frootbox\Core\Support\Message;

/**
 * Checks the permission rules given to role.create and role.update.
 */
final class RoleRules
{
    /**
     * @param list<string> $permissions
     */
    public static function check(string $action, array $permissions): void
    {
        $errors = [];

        foreach ($permissions as $index => $rule) {
            if (!PermissionRules::isValid($rule)) {
                $errors['/permissions/' . $index] = new Message('"{rule}" is not a rule. Use "*", "page.*", "page.create" or "-page.delete".', ['rule' => $rule]);
            }
        }

        if ([] !== $errors) {
            throw new InvalidInputException($action, $errors);
        }
    }
}
