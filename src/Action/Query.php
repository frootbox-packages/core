<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Action;

/**
 * Reads data, never changes it. Built like an Action, but with #[AsQuery],
 * run with Runner::ask() and not logged.
 *
 * @template-covariant TResult = mixed
 */
interface Query {}
