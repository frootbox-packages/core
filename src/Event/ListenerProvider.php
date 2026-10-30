<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Event;

use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\ListenerProviderInterface;

/**
 * Knows which listeners react to which event.
 *
 *     $listeners->listen(PagePublished::class, ClearPageCache::class);
 *     $listeners->listen(PagePublished::class, [Sitemap::class, 'refresh'], priority: -10);
 *     $listeners->listen(PagePublished::class, fn (PagePublished $event) => ...);
 *
 * A listener is a closure or invokable object, the id of an invokable
 * service, or [service id, method]. Services are taken from the container
 * only when their event is dispatched. A listener for a class or interface
 * also receives all events that extend or implement it. Higher priority
 * runs first, equal priority in the order of registration.
 */
final class ListenerProvider implements ListenerProviderInterface
{
    /**
     * @var array<string, list<array{int, int, object|non-empty-string|array{non-empty-string, non-empty-string}}>>
     */
    private array $listeners = [];

    private int $sequence = 0;

    public function __construct(private readonly ContainerInterface $container) {}

    /**
     * @param class-string                                                         $event
     * @param object|non-empty-string|array{non-empty-string, non-empty-string} $listener
     */
    public function listen(string $event, object|string|array $listener, int $priority = 0): void
    {
        if (\is_object($listener) && !\is_callable($listener)) {
            throw new InvalidListenerException(\sprintf('Listener of class %s for %s is not callable. Add an __invoke() method.', $listener::class, $event));
        }

        $this->listeners[$event][] = [$priority, $this->sequence++, $listener];
    }

    /**
     * @return iterable<callable(object): mixed>
     */
    public function getListenersForEvent(object $event): iterable
    {
        $matching = [];

        foreach ($this->listeners as $type => $listeners) {
            if ($event instanceof $type) {
                array_push($matching, ...$listeners);
            }
        }

        usort($matching, static fn(array $a, array $b): int => [$b[0], $a[1]] <=> [$a[0], $b[1]]);

        foreach ($matching as [, , $listener]) {
            yield $this->resolve($listener);
        }
    }

    /**
     * @param object|non-empty-string|array{non-empty-string, non-empty-string} $listener
     *
     * @return callable(object): mixed
     */
    private function resolve(object|string|array $listener): callable
    {
        if (\is_object($listener)) {
            return $this->callable($listener, $listener::class);
        }

        if (\is_string($listener)) {
            $service = $this->container->get($listener);

            return $this->callable($service, $listener);
        }

        [$id, $method] = $listener;
        $service = $this->container->get($id);

        return $this->callable(\is_object($service) ? [$service, $method] : null, $id . '::' . $method);
    }

    /**
     * @return callable(object): mixed
     */
    private function callable(mixed $listener, string $name): callable
    {
        if (!\is_callable($listener)) {
            throw new InvalidListenerException(\sprintf('Listener %s is not callable.', $name));
        }

        return $listener(...);
    }
}
