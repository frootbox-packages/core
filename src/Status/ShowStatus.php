<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Status;

use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Frootbox\Core\Action\AsQuery;
use Frootbox\Core\Action\Query;
use Frootbox\Core\Backup\BackupManager;
use Frootbox\Core\Comment\CommentEditor;
use Frootbox\Core\Extension\ExtensionPackage;
use Frootbox\Core\Extension\Extensions;
use Frootbox\Core\Frootbox;
use Frootbox\Core\Mail\Mailer;
use Frootbox\Core\ProjectPaths;
use Frootbox\Core\Queue\QueueOverview;
use Frootbox\Core\Revision\Revisions;
use Frootbox\Core\Revision\RevisionStatus;
use Frootbox\Core\Schedule\CronStatus;
use Throwable;

/**
 * The technical status of the website for monitoring and frootbox.de
 * (decision 0284). Its own permission, not system.*, so a service account
 * may read it and nothing else. Reads only, writes nothing, and every
 * value is quick to get, because it is asked for every few minutes.
 *
 * @implements Query<SiteStatus>
 */
#[AsQuery('status.show', 'Returns the technical status of the website: versions, cron, queue, backup, disk space, drafts, last change and how mails leave the server, without personal data or contents')]
final readonly class ShowStatus implements Query
{
    public function handle(
        Extensions $extensions,
        Connection $connection,
        QueueOverview $queue,
        BackupManager $backups,
        Revisions $revisions,
        CommentEditor $comments,
        ProjectPaths $paths,
        CronStatus $cron,
        Mailer $mailer,
    ): SiteStatus {
        $now = new DateTimeImmutable();
        $packages = $extensions->packages;
        usort($packages, static fn(ExtensionPackage $a, ExtensionPackage $b): int => strcmp($a->name, $b->name));
        $queueStatus = $queue->status();
        $latest = $backups->available() ? $backups->latest() : null;

        return new SiteStatus(
            system: ['name' => 'frootbox', 'version' => Frootbox::version()],
            php: ['version' => \PHP_VERSION],
            database: self::database($connection),
            extensions: array_map(static fn(ExtensionPackage $package): array => ['name' => $package->name, 'version' => $package->version], $packages),
            cron: ['lastRun' => self::time($queueStatus->cronLastRun), 'lastTick' => self::time($cron->lastTick())],
            queue: ['due' => $queueStatus->due, 'failed' => $queueStatus->failed],
            backup: $backups->available() ? [
                'last' => self::time($latest?->createdAt),
                'ageHours' => null === $latest ? null : intdiv(max(0, $now->getTimestamp() - $latest->createdAt->getTimestamp()), 3600),
            ] : null,
            storage: self::disk($paths->storage()),
            drafts: $revisions->count(['status' => RevisionStatus::Draft]),
            comments: $comments->count(null),
            lastEdit: self::time($revisions->lastChange()),
            mail: self::mail($mailer),
        );
    }

    /**
     * @return array{kind: string, version: string|null}|null
     */
    private static function database(Connection $connection): ?array
    {
        try {
            $platform = $connection->getDatabasePlatform();
            $version = $connection->getServerVersion();
        } catch (Throwable) {
            return null;
        }

        $kind = match (true) {
            $platform instanceof SQLitePlatform => 'sqlite',
            $platform instanceof MariaDBPlatform => 'mariadb',
            $platform instanceof AbstractMySQLPlatform => 'mysql',
            default => strtolower(new \ReflectionClass($platform)->getShortName()),
        };

        return ['kind' => $kind, 'version' => '' === $version ? null : $version];
    }

    /**
     * Only the kind of transport and where it is set, no server, account
     * or sender (decision 0309). The hub warns without SMTP.
     *
     * @return array{transport: string, source: string}|null
     */
    private static function mail(Mailer $mailer): ?array
    {
        try {
            $status = $mailer->status();
        } catch (Throwable) {
            return null;
        }

        return ['transport' => $status->transport, 'source' => $status->source];
    }

    /**
     * @return array{free: int|null, total: int|null}
     */
    private static function disk(string $folder): array
    {
        return ['free' => self::space('disk_free_space', $folder), 'total' => self::space('disk_total_space', $folder)];
    }

    /**
     * Webspace often disables these functions (disable_functions), the
     * status then knows no disk space instead of failing.
     *
     * @internal public for the test
     */
    public static function space(string $function, string $folder): ?int
    {
        if (!\in_array($function, ['disk_free_space', 'disk_total_space'], true) || !\function_exists($function) || !is_dir($folder)) {
            return null;
        }

        $space = @$function($folder);

        return \is_float($space) ? (int) $space : null;
    }

    private static function time(?DateTimeImmutable $time): ?string
    {
        return $time?->format(DateTimeInterface::ATOM);
    }
}
