<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Action;

use RuntimeException;

/**
 * Base of the errors a caller of an action or query can react to.
 */
abstract class ActionException extends RuntimeException {}
