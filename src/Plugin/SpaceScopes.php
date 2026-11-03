<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Plugin;

use Frootbox\Core\Auth\Scope;
use Frootbox\Core\Auth\ScopeResolver;

/**
 * The spaces of the plugins as scopes (decision 0245): a role given for
 * "space:frootbox/jobs/job:01J…" applies to that space of the jobs and
 * every job in it. Targets are a space ("<type>:<space>") or an object
 * ("<type>@<id>"), whose space the plugin knows.
 */
final readonly class SpaceScopes implements ScopeResolver
{
    public const string KIND = 'space';

    public function __construct(
        private Plugins $plugins,
        private PluginSpaces $spaces,
    ) {}

    /**
     * A space as a target, e.g. for creating an object in it.
     */
    public static function space(string $type, string $space): Scope
    {
        return new Scope(self::KIND, self::id($type, $space));
    }

    /**
     * An object as a target; it lies within the space it is in, the one
     * of its draft first.
     */
    public static function object(string $type, string $id): Scope
    {
        return new Scope(self::KIND, $type . '@' . $id);
    }

    /**
     * The id of the scope of a space, e.g. "frootbox/jobs/job:default".
     */
    public static function id(string $type, string $space): string
    {
        return $type . ':' . $space;
    }

    public function covers(string $scope, string $target, bool $strict): bool
    {
        return $this->resolve($target) === $scope;
    }

    public function exists(string $id): bool
    {
        $parts = explode(':', $id, 2);

        return 2 === \count($parts) && $this->spaces->exists($parts[0], $parts[1]);
    }

    /**
     * The id of the space a target lies within, null for an unknown object.
     */
    private function resolve(string $target): ?string
    {
        $at = strrpos($target, '@');

        if (false === $at) {
            return $target;
        }

        $type = substr($target, 0, $at);
        $space = $this->plugins->get($type)?->space(substr($target, $at + 1));

        return null === $space ? null : self::id($type, $space);
    }
}
