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
