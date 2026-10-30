<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Action;

use Doctrine\DBAL\Connection;
use Frootbox\Core\Action\Schema\InputMapper;
use Frootbox\Core\Action\Schema\InputValidator;
use Frootbox\Core\Auth\Permissions;
use Invoker\InvokerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Runs actions and queries. Every way into Frootbox ends here: backend,
 * REST, MCP, AI, app and console.
 *
 * An action is checked (permission, input schema), runs in a database
 * transaction, is logged with its changes and fires ActionExecuted. A
 * query is only checked and run.
 */
final readonly class Runner
{
    public function __construct(
        private Catalog $catalog,
        private InvokerInterface $invoker,
        private CurrentContext $context,
        private Permissions $permissions,
        private InputValidator $validator,
        private ActionLog $log,
        private Journal $journal,
        private Connection $connection,
        private EventDispatcherInterface $events,
        private LoggerInterface $logger,
    ) {}

    /**
     * @template TResult
     *
     * @param Action<TResult> $action
     *
     * @return TResult
     */
    public function run(Action $action, ?Context $context = null): mixed
    {
        $descriptor = $this->catalog->forClass($action::class);
        $context ??= $this->context->get();
        $input = InputMapper::fromObject($action);
        $logged = InputMapper::mask($descriptor->class, $input);
        $parentId = $this->journal->currentEntryId();

        $this->check($descriptor, $input, $context, $parentId);
        $started = hrtime(true);

        try {
            [$result, $entryId] = $this->connection->transactional(
                fn(): array => $this->context->runAs($context, fn(): array => $this->execute($descriptor, $action, $context, $logged, $parentId, $started)),
            );
        } catch (Throwable $error) {
            $this->bookkeeping(fn() => $this->log->failed($descriptor, $context, $logged, $parentId, $error, self::since($started)));

            throw $error;
        }

        $this->events->dispatch(new ActionExecuted($descriptor, $action, $result, $context, $entryId));

        return $result;
    }

    /**
     * @template TResult
     *
     * @param Query<TResult> $query
     *
     * @return TResult
     */
    public function ask(Query $query, ?Context $context = null): mixed
    {
        $descriptor = $this->catalog->forClass($query::class);
        $context ??= $this->context->get();
        $this->check($descriptor, InputMapper::fromObject($query), $context, null);

        return $this->context->runAs($context, fn(): mixed => $this->invoker->call([$query, 'handle']));
    }

    /**
     * Runs an action or query by name with input as plain values, e.g.
     * from a REST request, an MCP client or the AI.
     *
     * @param array<string, mixed> $input
     */
    public function call(string $name, array $input, ?Context $context = null): mixed
    {
        $descriptor = $this->catalog->get($name);
        $context ??= $this->context->get();
        $this->check($descriptor, $input, $context, $this->journal->currentEntryId());
        $object = InputMapper::toObject($descriptor->class, $input);

        return match (true) {
            $object instanceof Action => $this->run($object, $context),
            $object instanceof Query => $this->ask($object, $context),
            default => throw new DefinitionException(\sprintf('%s is neither an action nor a query.', $descriptor->class)),
        };
    }

    /**
     * @param Action<mixed>        $action
     * @param array<string, mixed> $logged
     *
     * @return array{mixed, int}
     */
    private function execute(Descriptor $descriptor, Action $action, Context $context, array $logged, ?int $parentId, int|float $started): array
    {
        $entryId = $this->log->start($descriptor, $context, $logged, $parentId);
        $this->journal->begin($entryId, $descriptor->undoable);

        try {
            $result = $this->invoker->call([$action, 'handle']);
        } catch (Throwable $error) {
            $this->journal->abort();

            throw $error;
        }

        $undoable = $this->journal->end();
        $this->log->succeeded($entryId, self::since($started), $undoable ?? false);

        return [$result, $entryId];
    }

    /**
     * Checks permission and input. A rejected action is logged.
     *
     * @param array<string, mixed> $input
     */
    private function check(Descriptor $descriptor, array $input, Context $context, ?int $parentId): void
    {
        try {
            if (!$descriptor->public && !$this->permissions->allows($context->actor, $descriptor->permission)) {
                throw AccessDeniedException::for($descriptor, $context->actor);
            }

            $errors = $this->validator->errors($descriptor->schema(), $input);

            if ([] !== $errors) {
                throw new InvalidInputException($descriptor->name, $errors);
            }
        } catch (AccessDeniedException|InvalidInputException $error) {
            if ($descriptor->isAction()) {
                $status = $error instanceof AccessDeniedException ? EntryStatus::Denied : EntryStatus::Invalid;
                $logged = InputMapper::mask($descriptor->class, $input);
                $this->bookkeeping(fn() => $this->log->rejected($descriptor, $context, $logged, $parentId, $status, $error->getMessage()));
            }

            throw $error;
        }
    }

    /**
     * Writes to the log without hiding the original error if that fails,
     * e.g. because the database is gone.
     *
     * @param callable(): mixed $write
     */
    private function bookkeeping(callable $write): void
    {
        try {
            $write();
        } catch (Throwable $error) {
            $this->logger->error('Could not write the action log: {message}', ['message' => $error->getMessage(), 'exception' => $error]);
        }
    }

    private static function since(int|float $started): int
    {
        return (int) round((hrtime(true) - $started) / 1_000_000);
    }
}
