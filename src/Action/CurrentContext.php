<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Action;

/**
 * The context of the current request or console run. It starts as a guest
 * on the web; the console switches to the system, a sign-in to the user.
 */
final class CurrentContext
{
    private Context $context;

    public function __construct()
    {
        $this->context = Context::guest();
    }

    public function get(): Context
    {
        return $this->context;
    }

    public function set(Context $context): void
    {
        $this->context = $context;
    }

    /**
     * Runs a callback in another context and switches back afterwards.
     *
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    public function runAs(Context $context, callable $callback): mixed
    {
        $previous = $this->context;
        $this->context = $context;

        try {
            return $callback();
        } finally {
            $this->context = $previous;
        }
    }
}
