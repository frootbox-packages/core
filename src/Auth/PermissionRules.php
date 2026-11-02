<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Auth;

use Frootbox\Core\Action\ActionName;
use InvalidArgumentException;

/**
 * The permissions of a role as a list of rules:
 *
 *     ['*']                               everything
 *     ['page.*', 'media.upload']          everything starting with "page." plus one permission
 *     ['page.*', '-page.delete']          a minus takes permissions out again
 *     ['acme/*', 'acme/shop/order.*']     everything of a vendor, part of an extension
 *
 * Exceptions win over grants, so the order does not matter.
 */
final readonly class PermissionRules
{
    private const string VENDOR = '[a-z0-9](?:[_.-]?[a-z0-9]+)*';

    private const string PATTERN = '#^-?(?:\*'
        . '|' . self::VENDOR . '/\*'
        . '|' . ActionName::PACKAGE . '/\*'
        . '|(?:' . ActionName::PACKAGE . '/)?' . ActionName::SEGMENT . '(?:\.' . ActionName::SEGMENT . ')*(?:\.\*)?'
        . ')$#';

    /** @var list<string> */
    private array $grants;

    /** @var list<string> */
    private array $exceptions;

    /**
     * @param list<string> $rules
     */
    public function __construct(array $rules)
    {
        $grants = [];
        $exceptions = [];

        foreach ($rules as $rule) {
            if (!self::isValid($rule)) {
                throw new InvalidArgumentException(\sprintf('"%s" is not a valid permission rule.', $rule));
            }

            if (str_starts_with($rule, '-')) {
                $exceptions[] = substr($rule, 1);
            } else {
                $grants[] = $rule;
            }
        }

        $this->grants = $grants;
        $this->exceptions = $exceptions;
    }

    public static function isValid(string $rule): bool
    {
        return 1 === preg_match(self::PATTERN, $rule);
    }

    public function allows(string $permission): bool
    {
        foreach ($this->exceptions as $rule) {
            if (self::matches($rule, $permission)) {
                return false;
            }
        }

        foreach ($this->grants as $rule) {
            if (self::matches($rule, $permission)) {
                return true;
            }
        }

        return false;
    }

    private static function matches(string $rule, string $permission): bool
    {
        return match (true) {
            '*' === $rule => true,
            str_ends_with($rule, '.*'), str_ends_with($rule, '/*') => str_starts_with($permission, substr($rule, 0, -1)),
            default => $rule === $permission,
        };
    }
}
