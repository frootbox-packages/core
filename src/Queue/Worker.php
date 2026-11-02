<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Queue;

use DateTimeImmutable;
use Frootbox\Core\Action\Channel;
use Frootbox\Core\Action\Context;
use Frootbox\Core\Action\CurrentContext;
use Frootbox\Core\Action\Schema\InputMapper;
use Frootbox\Core\Auth\Actor;
use Frootbox\Core\Auth\ActorType;
use Invoker\InvokerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Runs the jobs that are due, one after the other (decision 0221). Each
 * runs with the rights of whoever put it in the queue. A job that throws
 * runs again after 1, 3, 9, 27 … minutes until its attempts are used up.
 */
final readonly class Worker
{
    /** How long a job is locked for one worker. */
    public const int LOCK_SECONDS = 300;

    /** Failed jobs are removed after this many days. */
    public const int KEEP_FAILED_DAYS = 30;

    public function __construct(
        private Jobs $jobs,
        private JobClasses $classes,
        private CurrentContext $context,
        private InvokerInterface $invoker,
        private LoggerInterface $logger,
    ) {}

    /**
     * @param float $seconds no new job starts after this time
     */
    public function run(float $seconds = 50, ?int $limit = null): WorkerResult
    {
        $end = microtime(true) + $seconds;
        $this->jobs->pruneFailed(new DateTimeImmutable()->modify(\sprintf('-%d days', self::KEEP_FAILED_DAYS)));

        $done = $retried = $failed = 0;
        $tried = [];

        while (microtime(true) < $end && (null === $limit || \count($tried) < $limit)) {
            $now = new DateTimeImmutable();
            $job = $this->jobs->nextDue($now, $tried);

            if (null === $job) {
                break;
            }

            $tried[] = $job->id;

            if (!$this->jobs->claim($job, $now, $now->modify(\sprintf('+%d seconds', self::LOCK_SECONDS)))) {
                continue;
            }

            $error = $this->runJob($job);

            if (null === $error) {
                $this->jobs->delete($job->id);
                ++$done;
            } elseif ($job->attempts + 1 >= $job->maxAttempts) {
                $this->jobs->fail($job->id, $error, new DateTimeImmutable());
                $this->logger->error('Job {name} failed for good after {attempts} attempts: {error}', ['name' => $job->name, 'attempts' => $job->attempts + 1, 'error' => $error, 'job' => $job->id]);
                ++$failed;
            } else {
                $minutes = 3 ** $job->attempts;
                $this->jobs->retryAt($job->id, $error, new DateTimeImmutable()->modify(\sprintf('+%d minutes', $minutes)));
                $this->logger->warning('Job {name} failed, it runs again in {minutes} minutes: {error}', ['name' => $job->name, 'minutes' => $minutes, 'error' => $error, 'job' => $job->id]);
                ++$retried;
            }
        }

        return new WorkerResult($done, $retried, $failed);
    }

    /**
     * @return string|null the error, null on success
     */
    private function runJob(StoredJob $stored): ?string
    {
        $class = $this->classes->get($stored->name);

        if (null === $class) {
            return \sprintf('The job "%s" is not registered, e.g. because its extension was removed.', $stored->name);
        }

        try {
            $job = InputMapper::toObject($class, $stored->input);

            $this->context->runAs(new Context(self::actor($stored), Channel::System), fn(): mixed => $this->invoker->call([$job, 'handle']));
        } catch (Throwable $exception) {
            return mb_substr($exception::class . ': ' . $exception->getMessage(), 0, 2000);
        }

        return null;
    }

    private static function actor(StoredJob $job): Actor
    {
        return match (ActorType::tryFrom($job->actorType)) {
            ActorType::User => null === $job->actorId ? Actor::guest() : Actor::user($job->actorId, $job->actorName ?? ''),
            ActorType::System => Actor::system(),
            default => Actor::guest(),
        };
    }
}
