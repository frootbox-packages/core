<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Auth;

use Frootbox\Core\Action\DefinitionException;
use Psr\Container\ContainerInterface;

/**
 * The registered kinds of scopes. Each resolver is created through the
 * container when it is first needed.
 */
final readonly class ScopeResolvers
{
    /**
     * @param array<string, class-string<ScopeResolver>> $classes by kind
     */
    public function __construct(
        private array $classes,
        private ContainerInterface $container,
    ) {}

    public function has(string $kind): bool
    {
        return isset($this->classes[$kind]);
    }

    /**
     * Whether a role given within $scope applies to $target; never for
     * different kinds or a kind nobody registered.
     */
    public function covers(Scope $scope, Scope $target): bool
    {
        return $scope->kind === $target->kind
            && $this->has($target->kind)
            && $this->resolver($target->kind)->covers($scope->id, $target->id, $target->strict);
    }

    /**
     * Of these targets of one kind, those within the scopes; one call to
     * the resolver if it lists them at once (ListsWithin).
     *
     * @param list<string> $scopes  Ids of the scopes of the kind
     * @param list<string> $targets
     *
     * @return list<string>
     */
    public function filter(string $kind, array $scopes, array $targets): array
    {
        if ([] === $scopes || !$this->has($kind)) {
            return [];
        }

        $resolver = $this->resolver($kind);

        if ($resolver instanceof ListsWithin) {
            return array_values(array_intersect($targets, $resolver->within($scopes)));
        }

        return array_values(array_filter($targets, static fn(string $target): bool => array_any($scopes, static fn(string $scope): bool => $resolver->covers($scope, $target, false))));
    }

    public function exists(Scope $scope): bool
    {
        return $this->has($scope->kind) && $this->resolver($scope->kind)->exists($scope->id);
    }

    private function resolver(string $kind): ScopeResolver
    {
        $resolver = $this->container->get($this->classes[$kind]);

        if (!$resolver instanceof ScopeResolver) {
            throw new DefinitionException(\sprintf('%s must implement %s.', $this->classes[$kind], ScopeResolver::class));
        }

        return $resolver;
    }
}
