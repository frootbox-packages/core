<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Action;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Frootbox\Core\Action\Schema\Description;
use Frootbox\Core\Action\Schema\Length;
use Frootbox\Core\Action\Schema\Range;
use Frootbox\Database\EntityMapper;
use Frootbox\Database\Values;

/**
 * The whole log of actions with filters (decision 0174), for superusers
 * and roles with the permission action.log: also rejected and failed
 * runs, by person, action, channel, time and status.
 *
 * @implements Query<ActionLogPage>
 */
#[AsQuery('action.log', 'Returns the log of actions, newest first: who ran which action when, through which channel and on which device, with input (secrets masked) and status. Filters by user, action (a trailing * for all of a prefix, e.g. page.*), channel, status and time; before pages through older entries')]
final readonly class ListActionLog implements Query
{
    public function __construct(
        #[Description('Only actions of this user')]
        public ?int $user = null,
        #[Description('Only this action, or all starting with what comes before a trailing *')]
        #[Length(max: 100)]
        public ?string $action = null,
        public ?Channel $channel = null,
        public ?EntryStatus $status = null,
        #[Description('Only entries from this time on')]
        public ?DateTimeImmutable $from = null,
        #[Description('Only entries before this time')]
        public ?DateTimeImmutable $to = null,
        #[Description('Also the nested actions an action ran')]
        public bool $nested = false,
        #[Range(min: 1, max: History::MAX)]
        public int $limit = 50,
        #[Description('Only entries older than this one, the next of the previous page')]
        public ?int $before = null,
    ) {}

    public function handle(Connection $connection): ActionLogPage
    {
        $quote = $connection->quoteSingleIdentifier(...);
        $query = $connection->createQueryBuilder()
            ->select('*')
            ->from($quote(ActionLog::ENTRIES))
            ->orderBy($quote('id'), 'DESC')
            ->setMaxResults($this->limit + 1);

        if (null !== $this->user) {
            $query->andWhere($quote('actor_id') . ' = :user')->setParameter('user', $this->user);
        }

        if (null !== $this->action && str_ends_with($this->action, '*')) {
            $query->andWhere($quote('action') . ' LIKE :action')->setParameter('action', addcslashes(rtrim($this->action, '*'), '%_\\') . '%');
        } elseif (null !== $this->action) {
            $query->andWhere($quote('action') . ' = :action')->setParameter('action', $this->action);
        }

        if (null !== $this->channel) {
            $query->andWhere($quote('channel') . ' = :channel')->setParameter('channel', $this->channel->value);
        }

        if (null !== $this->status) {
            $query->andWhere($quote('status') . ' = :status')->setParameter('status', $this->status->value);
        }

        if (null !== $this->from) {
            $query->andWhere($quote('created_at') . ' >= :from')->setParameter('from', Values::toDatabase($this->from));
        }

        if (null !== $this->to) {
            $query->andWhere($quote('created_at') . ' < :to')->setParameter('to', Values::toDatabase($this->to));
        }

        if (!$this->nested) {
            $query->andWhere($quote('parent_id') . ' IS NULL');
        }

        if (null !== $this->before) {
            $query->andWhere($quote('id') . ' < :before')->setParameter('before', $this->before);
        }

        $entries = array_map(static fn(array $row): LogEntry => EntityMapper::hydrate(LogEntry::class, $row), $query->executeQuery()->fetchAllAssociative());
        $next = \count($entries) > $this->limit ? $entries[$this->limit - 1]->id : null;

        return new ActionLogPage(\array_slice($entries, 0, $this->limit), $next);
    }
}
