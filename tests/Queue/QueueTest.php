<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Queue;

use DateTimeImmutable;
use Frootbox\Core\Action\Channel;
use Frootbox\Core\Action\Context;
use Frootbox\Core\Action\CurrentContext;
use Frootbox\Core\Action\DefinitionException;
use Frootbox\Core\Auth\Actor;
use Frootbox\Core\Extension\Registry;
use Frootbox\Core\Http\AfterResponse;
use Frootbox\Core\ProjectPaths;
use Frootbox\Core\Queue\DeleteJob;
use Frootbox\Core\Queue\JobClasses;
use Frootbox\Core\Queue\Jobs;
use Frootbox\Core\Queue\ListJobs;
use Frootbox\Core\Queue\Queue;
use Frootbox\Core\Queue\QueueOverview;
use Frootbox\Core\Queue\RetryJob;
use Frootbox\Core\Queue\ShowQueueStatus;
use Frootbox\Core\Queue\Worker;
use Frootbox\Core\Schedule\CronStatus;
use Frootbox\Core\Schedule\Scheduler;
use Frootbox\Core\Tests\Fixtures\Notes\Outbox;
use Frootbox\Core\Tests\Fixtures\Notes\QueueNote;
use Frootbox\Core\Tests\Fixtures\Notes\SendNote;
use Frootbox\Core\Tests\Support\NotesTestCase;
use Frootbox\Database\Values;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(Queue::class)]
#[CoversClass(Worker::class)]
#[CoversClass(Jobs::class)]
#[CoversClass(JobClasses::class)]
#[CoversClass(QueueOverview::class)]
#[CoversClass(CronStatus::class)]
final class QueueTest extends NotesTestCase
{
    public function testRunsAJobWithTheRightsOfWhoQueuedIt(): void
    {
        $id = $this->get(CurrentContext::class)->runAs(
            new Context(Actor::user(7, 'Ada'), Channel::Web),
            fn(): string => $this->get(Queue::class)->push(new SendNote(1)),
        );

        self::assertSame([], Outbox::$messages, 'Nothing runs before the worker.');
        $result = $this->worker()->run(5);

        self::assertSame(1, $result->done);
        self::assertSame(['sent #1 as Ada from notes@example.test'], Outbox::$messages);
        self::assertNull($this->get(Jobs::class)->find($id), 'A done job is removed.');
    }

    public function testAJobBelongsToTheTransactionOfItsAction(): void
    {
        $this->runner()->run(new QueueNote(1));

        try {
            $this->runner()->run(new QueueNote(2, failAfterwards: true));
            self::fail('The action should fail.');
        } catch (\RuntimeException) {
        }

        self::assertSame(['acme/notes/note.send'], array_map(static fn(object $job): string => $job->name, $this->runner()->ask(new ListJobs())->jobs));
        $this->worker()->run(5);
        self::assertSame(['sent #1 as System from notes@example.test'], Outbox::$messages);
    }

    public function testRetriesAFailingJobLaterUntilItsAttemptsAreUsedUp(): void
    {
        $id = $this->runner()->run(new QueueNote(1, failInJob: true));

        $result = $this->worker()->run(5);
        self::assertSame(1, $result->retried);
        $job = $this->get(Jobs::class)->find($id);
        self::assertNotNull($job);
        self::assertSame(1, $job->attempts);
        self::assertStringContainsString('The note server is down.', (string) $job->lastError);
        self::assertEqualsWithDelta(new DateTimeImmutable('+1 minute')->getTimestamp(), $job->availableAt->getTimestamp(), 5);
        self::assertSame(0, $this->worker()->run(5)->total(), 'It waits a minute.');

        $this->makeDue($id);
        $this->worker()->run(5);
        $job = $this->get(Jobs::class)->find($id);
        self::assertNotNull($job);
        self::assertEqualsWithDelta(new DateTimeImmutable('+3 minutes')->getTimestamp(), $job->availableAt->getTimestamp(), 5);

        $this->makeDue($id);
        self::assertSame(1, $this->worker()->run(5)->failed);
        $list = $this->runner()->ask(new ListJobs());
        self::assertSame('failed', $list->jobs[0]->state);
        self::assertSame('Sends a note', $list->jobs[0]->description);
        self::assertSame(3, $list->jobs[0]->attempts);
        self::assertSame(1, $list->status->failed);
        self::assertSame(0, $this->worker()->run(5)->total(), 'A failed job does not run again by itself.');

        // Retry puts it back with all its attempts, delete removes it.
        $this->runner()->run(new RetryJob($id));
        $job = $this->get(Jobs::class)->find($id);
        self::assertNotNull($job);
        self::assertNull($job->failedAt);
        self::assertSame(0, $job->attempts);
        self::assertSame(1, $this->runner()->ask(new ShowQueueStatus())->due);

        $this->runner()->run(new DeleteJob($id));
        self::assertSame([], $this->runner()->ask(new ListJobs())->jobs);
    }

    public function testADelayedJobWaits(): void
    {
        $this->runner()->run(new QueueNote(1, delay: 600));

        self::assertSame(0, $this->worker()->run(5)->total());
        self::assertSame('waiting', $this->runner()->ask(new ListJobs())->jobs[0]->state);
    }

    public function testPushOnceLeavesAWaitingJobAlone(): void
    {
        $queue = $this->get(Queue::class);
        $push = fn(): ?string => $this->get(CurrentContext::class)->runAs(Context::system(), fn(): ?string => $queue->pushOnce(new SendNote(1), 600));

        $first = $push();

        self::assertNotNull($first);
        self::assertNull($push(), 'One waits already.');

        // A running one may have read its data already: another one waits.
        $job = $this->get(Jobs::class)->find($first);
        self::assertNotNull($job);
        $now = new DateTimeImmutable();
        $this->get(Jobs::class)->claim($job, $now, $now->modify('+5 minutes'));

        self::assertNotNull($push());
    }

    public function testAJobOfARemovedExtensionFails(): void
    {
        $id = $this->runner()->run(new QueueNote(1));
        $this->connection()->update('jobs', ['name' => 'acme/gone/thing.do', 'max_attempts' => 1], ['id' => $id]);

        self::assertSame(1, $this->worker()->run(5)->failed);
        self::assertStringContainsString('not registered', (string) $this->get(Jobs::class)->find($id)?->lastError);
    }

    public function testRemovesFailedJobsAfterThirtyDays(): void
    {
        $id = $this->runner()->run(new QueueNote(1));
        $this->connection()->update('jobs', ['failed_at' => new DateTimeImmutable('-31 days')->format(Values::DATE_FORMAT)], ['id' => $id]);

        $this->worker()->run(5);

        self::assertNull($this->get(Jobs::class)->find($id));
    }

    public function testWarnsWhenJobsAreDueAndCronDoesNotRun(): void
    {
        self::assertFalse($this->runner()->ask(new ShowQueueStatus())->cronMissing, 'Without due jobs there is nothing to warn about.');

        $this->runner()->run(new QueueNote(1));
        $status = $this->runner()->ask(new ShowQueueStatus());
        self::assertTrue($status->cronMissing);
        self::assertNull($status->cronLastRun);
        self::assertStringEndsWith('/bin/frootbox schedule:run', $status->cronLine);

        $this->get(CronStatus::class)->touch();
        self::assertFalse($this->runner()->ask(new ShowQueueStatus())->cronMissing);
    }

    public function testWithoutCronAPageRequestRunsTheQueueAfterTheResponse(): void
    {
        $this->runner()->run(new QueueNote(1));

        $this->get(Scheduler::class)->runIfDue();
        self::assertSame([], Outbox::$messages);
        $after = $this->get(AfterResponse::class);
        self::assertTrue($after->pending());
        $after->run();
        self::assertSame(['sent #1 as System from notes@example.test'], Outbox::$messages);

        // With cron, requests leave the queue alone.
        $this->runner()->run(new QueueNote(2));
        self::assertTrue(touch($this->get(ProjectPaths::class)->var('cache/schedule'), time() - 120));
        $this->get(CronStatus::class)->touch();
        $this->get(Scheduler::class)->runIfDue();
        self::assertFalse($after->pending());
        self::assertSame(1, $this->runner()->ask(new ShowQueueStatus())->due);
    }

    public function testAJobIsNamedAfterItsPackage(): void
    {
        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage('must be named "acme/other/note.send"');

        $registry = new Registry();
        $registry->forPackage('acme/other', static fn() => $registry->job(SendNote::class));
    }

    private function worker(): Worker
    {
        return $this->get(Worker::class);
    }

    private function makeDue(string $id): void
    {
        $this->connection()->update('jobs', ['available_at' => new DateTimeImmutable('-1 second')->format(Values::DATE_FORMAT)], ['id' => $id]);
    }
}
