<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Component;

use RuntimeException;

/**
 * Blocks or layouts with errors in their schema or files.
 */
final class ComponentException extends RuntimeException
{
    /**
     * @param list<string> $errors
     */
    public function __construct(public readonly array $errors)
    {
        parent::__construct(1 === \count($errors)
            ? $errors[0]
            : \sprintf("%d problems with blocks and layouts:\n- %s", \count($errors), implode("\n- ", $errors)));
    }
}
