<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Event;

use DI\Container;
use Frootbox\Core\Event\EventDispatcher;
use Frootbox\Core\Event\InvalidListenerException;
use Frootbox\Core\Event\ListenerProvider;
use Frootbox\Core\Event\StoppableEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EventDispatcher::class)]
#[CoversClass(ListenerProvider::class)]
#[CoversClass(StoppableEvent::class)]
final class EventDispatcherTest extends TestCase
{
    private Container $container;

    private ListenerProvider $listeners;

    private EventDispatcher $dispatcher;

    protected function setUp(): void
    {
        $this->container = new Container();
        $this->listeners = new ListenerProvider($this->container);
        $this->dispatcher = new EventDispatcher($this->listeners);
    }

    public function testReturnsTheEvent(): void
    {
        $event = new SimpleEvent();

        self::assertSame($event, $this->dispatcher->dispatch($event));
    }

    public function testRunsListenersByPriorityThenRegistration(): void
    {
        $this->listeners->listen(RecordingEvent::class, static fn(RecordingEvent $e) => $e->calls[] = 'b');
        $this->listeners->listen(RecordingEvent::class, static fn(RecordingEvent $e) => $e->calls[] = 'first', priority: 10);
        $this->listeners->listen(RecordingEvent::class, static fn(RecordingEvent $e) => $e->calls[] = 'c');
        $this->listeners->listen(RecordingEvent::class, static fn(RecordingEvent $e) => $e->calls[] = 'last', priority: -5);

        $event = $this->dispatcher->dispatch(new SimpleEvent());

        self::assertSame(['first', 'b', 'c', 'last'], $event->calls);
    }

    public function testListenersForParentsAndInterfacesReceiveTheEvent(): void
    {
        $this->listeners->listen(PageEvent::class, static fn(RecordingEvent $e) => $e->calls[] = 'interface');
        $this->listeners->listen(RecordingEvent::class, static fn(RecordingEvent $e) => $e->calls[] = 'parent');
        $this->listeners->listen(PagePublished::class, static fn(RecordingEvent $e) => $e->calls[] = 'class');

        self::assertSame(['interface', 'parent', 'class'], $this->dispatcher->dispatch(new PagePublished())->calls);
        self::assertSame(['parent'], $this->dispatcher->dispatch(new SimpleEvent())->calls);
    }

    public function testLoadsServicesOnlyWhenTheirEventIsDispatched(): void
    {
        $this->listeners->listen(RecordingEvent::class, InvokableListener::class);
        $this->listeners->listen(RecordingEvent::class, [MethodListener::class, 'onEvent']);
        $this->listeners->listen(PagePublished::class, [UnusedListener::class, 'onEvent']);

        self::assertSame(['invoked', 'method'], $this->dispatcher->dispatch(new SimpleEvent())->calls);
        self::assertFalse(UnusedListener::$created);
    }

    public function testStopsWhenAListenerStopsPropagation(): void
    {
        $this->listeners->listen(RecordingEvent::class, static function (RecordingEvent $e): void {
            $e->calls[] = 'stopper';
            $e->stopPropagation();
        });
        $this->listeners->listen(RecordingEvent::class, static fn(RecordingEvent $e) => $e->calls[] = 'never');

        self::assertSame(['stopper'], $this->dispatcher->dispatch(new SimpleEvent())->calls);
    }

    public function testDoesNothingForAnAlreadyStoppedEvent(): void
    {
        $this->listeners->listen(RecordingEvent::class, static fn(RecordingEvent $e) => $e->calls[] = 'never');
        $event = new SimpleEvent();
        $event->stopPropagation();

        self::assertSame([], $this->dispatcher->dispatch($event)->calls);
    }

    public function testRejectsObjectsThatCannotBeCalled(): void
    {
        $this->expectException(InvalidListenerException::class);

        $this->listeners->listen(RecordingEvent::class, new SimpleEvent());
    }

    public function testRejectsServicesWithoutTheMethod(): void
    {
        $this->listeners->listen(RecordingEvent::class, [MethodListener::class, 'missing']);

        $this->expectException(InvalidListenerException::class);

        $this->dispatcher->dispatch(new SimpleEvent());
    }

    public function testListenerExceptionsAreNotCaught(): void
    {
        $this->listeners->listen(RecordingEvent::class, static fn() => throw new \RuntimeException('listener failed'));

        $this->expectExceptionMessage('listener failed');

        $this->dispatcher->dispatch(new SimpleEvent());
    }
}

interface PageEvent {}

abstract class RecordingEvent extends StoppableEvent
{
    /** @var list<string> */
    public array $calls = [];
}

final class SimpleEvent extends RecordingEvent {}

final class PagePublished extends RecordingEvent implements PageEvent {}

final class InvokableListener
{
    public function __invoke(RecordingEvent $event): void
    {
        $event->calls[] = 'invoked';
    }
}

final class MethodListener
{
    public function onEvent(RecordingEvent $event): void
    {
        $event->calls[] = 'method';
    }
}

final class UnusedListener
{
    public static bool $created = false;

    public function __construct()
    {
        self::$created = true;
    }

    public function onEvent(RecordingEvent $event): void {}
}
