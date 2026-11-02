<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Schedule;

use DateTimeImmutable;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Frootbox\Core\Action\Context;
use Frootbox\Core\Action\InvalidInputException;
use Frootbox\Core\Action\Runner;
use Frootbox\Core\Page\PageReader;
use Frootbox\Core\Page\PublishPages;
use Frootbox\Core\Page\SchedulePage;
use Frootbox\Core\Page\UnpublishPage;
use Frootbox\Core\Preview\PreviewLinks;
use Frootbox\Core\Preview\PrunePreviews;
use Frootbox\Core\ProjectPaths;
use Frootbox\Core\Revision\Revisions;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Publishes and takes offline what is due, with the normal actions as the
 * system (decision 0107). Started by bin/frootbox schedule:run from a cron
 * job, otherwise at most once a minute by a page request.
 */
final readonly class Scheduler
{
    /** Seconds between two checks from page requests. */
    public const int INTERVAL = 60;

    public function __construct(
        private Revisions $revisions,
        private PageReader $pages,
        private Runner $runner,
        private ProjectPaths $paths,
        private LoggerInterface $logger,
        private PreviewLinks $previews,
    ) {}

    /**
     * Runs what is due now.
     */
    public function run(?DateTimeImmutable $now = null): ScheduleResult
    {
        $due = $this->revisions->due($now ?? new DateTimeImmutable());
        $published = $this->publishPages($due['publish'][PageReader::TYPE] ?? []);
        $unpublished = [];
        $failed = [];

        foreach ($due['unpublish'][PageReader::TYPE] ?? [] as $id) {
            try {
                $this->runner->run(new UnpublishPage($id), Context::system());
                $unpublished[] = $id;
            } catch (InvalidInputException) {
                // Already offline with a page above it.
            }
        }

        foreach ($due['publish'] + $due['unpublish'] as $type => $ids) {
            if (PageReader::TYPE !== $type) {
                $failed[] = \sprintf('%s: no schedule for this type yet (%s)', $type, implode(', ', $ids));
            }
        }

        // Preview links 30 days after they ended (decision 0154); the action only
        // runs when there is something to delete, so the log stays readable.
        try {
            if ([] !== $this->previews->endedBefore(PrunePreviews::before())) {
                $this->runner->run(new PrunePreviews(), Context::system());
            }
        } catch (TableNotFoundException) {
            // Not migrated yet.
        }

        return new ScheduleResult($published['done'], $unpublished, [...$published['failed'], ...$failed]);
    }

    /**
     * Runs what is due if the last check from a request was more than a
     * minute ago. Errors are logged and never stop the request.
     */
    public function runIfDue(): void
    {
        $marker = $this->paths->var('cache/schedule');
        $last = is_file($marker) ? filemtime($marker) : false;

        if (false !== $last && $last > time() - self::INTERVAL) {
            return;
        }

        $directory = \dirname($marker);

        if ((!is_dir($directory) && !@mkdir($directory, 0o775, true) && !is_dir($directory)) || !@touch($marker)) {
            return;
        }

        try {
            $result = $this->run();

            if ([] !== $result->failed) {
                $this->logger->warning('Scheduled publishing failed in part.', ['failed' => $result->failed]);
            }
        } catch (TableNotFoundException) {
            // Not migrated yet: nothing can be due.
        } catch (Throwable $error) {
            $this->logger->error('Scheduled publishing failed.', ['exception' => $error]);
        }
    }

    /**
     * Publishes the due pages together. If that is refused, each on its
     * own; a page that cannot be published loses its time, so it is not
     * tried again every minute.
     *
     * @param list<string> $ids
     *
     * @return array{done: list<string>, failed: list<string>}
     */
    private function publishPages(array $ids): array
    {
        if ([] === $ids) {
            return ['done' => [], 'failed' => []];
        }

        try {
            $this->runner->run(new PublishPages($ids), Context::system());

            return ['done' => $ids, 'failed' => []];
        } catch (InvalidInputException) {
        }

        $done = [];
        $failed = [];

        foreach ($ids as $id) {
            try {
                $this->runner->run(new PublishPages([$id]), Context::system());
                $done[] = $id;
            } catch (InvalidInputException $error) {
                $failed[] = \sprintf('page %s: %s', $id, implode(' ', $error->errors));
                $this->runner->run(new SchedulePage($id, null, $this->pages->details($id)->unpublishAt), Context::system());
            }
        }

        return ['done' => $done, 'failed' => $failed];
    }
}
