<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Action;

use Frootbox\Core\Action\Schema\Fields;
use Frootbox\Core\Action\Schema\InputMapper;
use LogicException;

/**
 * Lets an action with effects outside the database stay undoable: it
 * names the action that makes up for it, e.g. a cancellation mail.
 *
 *     public function handle(Mailer $mailer, Compensations $compensations): void
 *     {
 *         $mailer->send(...);
 *         $compensations->add(new SendCancellation($this->orderId));
 *     }
 *
 * The compensating action runs when the action is undone.
 */
final readonly class Compensations
{
    public function __construct(
        private Catalog $catalog,
        private Journal $journal,
        private ActionLog $log,
    ) {}

    /**
     * @param Action<mixed> $action
     */
    public function add(Action $action): void
    {
        $descriptor = $this->catalog->forClass($action::class);

        if (!$this->journal->isRecording()) {
            throw new LogicException('Compensations can only be added while an action runs.');
        }

        foreach (Fields::of($descriptor->class) as $field) {
            if ($field->isSensitive()) {
                throw new DefinitionException(\sprintf('%s cannot compensate, its input %s is sensitive and would be stored.', $descriptor->class, $field->name));
            }
        }

        $this->log->recordChange(ChangeKind::Action, $descriptor->name, null, null, InputMapper::fromObject($action));
        $this->journal->markCompensated();
    }
}
