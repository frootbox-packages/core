<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Auth;

use Stringable;

/**
 * What an action works on, e.g. the page "01J…", for roles given only
 * within a scope such as a branch of the page tree (decision 0118).
 * Strict targets must lie below the top of a branch, not be the top
 * itself, e.g. for deleting a page.
 */
final readonly class Scope implements Stringable
{
    public const string KIND_PATTERN = '^[a-z][a-z0-9-]*$';

    public function __construct(
        public string $kind,
        public string $id,
        public bool $strict = false,
    ) {}

    public static function page(string $id, bool $strict = false): self
    {
        return new self('page', $id, $strict);
    }

    /**
     * "page:01J…" as stored with a role assignment, null if it is no scope.
     */
    public static function fromString(string $scope): ?self
    {
        $colon = strpos($scope, ':');

        if (false === $colon || 1 !== preg_match('/' . self::KIND_PATTERN . '/', substr($scope, 0, $colon)) || $colon === \strlen($scope) - 1) {
            return null;
        }

        return new self(substr($scope, 0, $colon), substr($scope, $colon + 1));
    }

    public function __toString(): string
    {
        return $this->kind . ':' . $this->id;
    }
}
