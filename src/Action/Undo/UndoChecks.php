<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Action\Undo;

use Frootbox\Core\Action\Change;
use Frootbox\Core\Action\DefinitionException;
use Psr\Container\ContainerInterface;

/**
 * The registered undo checks. Each is created through the container when
 * an undo runs.
 */
final readonly class UndoChecks
{
    /**
     * @param list<class-string<UndoCheck>> $classes
     */
    public function __construct(
        private array $classes,
        private ContainerInterface $container,
    ) {}

    /**
     * @param list<Change> $changes
     *
     * @return list<UndoConflict>
     */
    public function conflicts(array $changes): array
    {
        $conflicts = [];

        foreach ($this->classes as $class) {
            $check = $this->container->get($class);

            if (!$check instanceof UndoCheck) {
                throw new DefinitionException(\sprintf('%s must implement %s.', $class, UndoCheck::class));
            }

            array_push($conflicts, ...$check->check($changes));
        }

        return $conflicts;
    }
}
