<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Queue;

use DateTimeImmutable;
use Frootbox\Core\Action\CurrentContext;
use Frootbox\Core\Action\DefinitionException;
use Frootbox\Core\Action\Schema\InputMapper;

/**
 * Puts jobs in the queue (decision 0221). Inside an action the job is
 * part of its transaction: if the action fails, there is no job.
 */
final readonly class Queue
{
    public function __construct(
        private Jobs $jobs,
        private JobClasses $classes,
        private CurrentContext $context,
    ) {}

    /**
     * @param int $delay seconds until the job may run
     *
     * @return string the id of the job
     */
    public function push(Job $job, int $delay = 0): string
    {
        $attribute = JobClasses::attribute($job::class);

        if ($this->classes->get($attribute->name) !== $job::class) {
            throw new DefinitionException(\sprintf('The job %s is not registered. Add $registry->job(%s::class).', $job::class, $job::class));
        }

        return $this->jobs->insert(
            $attribute->name,
            InputMapper::fromObject($job),
            $this->context->get()->actor,
            new DateTimeImmutable()->modify(\sprintf('+%d seconds', max(0, $delay))),
            $attribute->attempts,
        )->id;
    }
}
