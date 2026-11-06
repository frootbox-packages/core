<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Status;

/**
 * The status document of version 1 (decision 0284): technical values only,
 * no names, emails, contents or addresses of pages. Every value may be
 * null when it is not known, and readers ignore what they do not know,
 * so the module for Frootbox 1 and later versions can send the same.
 * Times are ISO 8601 with offset, sizes in bytes.
 */
final readonly class SiteStatus
{
    public const int SCHEMA = 1;

    /**
     * @param array{name: string, version: string}                 $system
     * @param array{version: string}                               $php
     * @param array{kind: string, version: string|null}|null       $database
     * @param list<array{name: string, version: string|null}>      $extensions
     * @param array{lastRun: string|null, lastTick: string|null}   $cron       lastTick: the last run the hub asked for (decision 0293)
     * @param array{due: int, failed: int}                         $queue
     * @param array{last: string|null, ageHours: int|null}|null    $backup     null where backups are not possible
     * @param array{free: int|null, total: int|null}               $storage    of the disk that holds storage/
     * @param int                                                  $drafts     unpublished drafts of all kinds
     * @param int|null                                             $comments   open threads in the preview
     * @param string|null                                          $lastEdit   when content was last saved or published
     * @param array{transport: string, source: string}|null        $mail       how mails leave the server (decision 0309): transport smtp, native, log, null or memory, source environment, settings or default
     */
    public function __construct(
        public array $system,
        public array $php,
        public ?array $database,
        public array $extensions,
        public array $cron,
        public array $queue,
        public ?array $backup,
        public array $storage,
        public int $drafts,
        public ?int $comments,
        public ?string $lastEdit,
        public ?array $mail = null,
        public int $schema = self::SCHEMA,
    ) {}
}
