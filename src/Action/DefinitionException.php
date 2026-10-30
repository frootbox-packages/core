<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Action;

use LogicException;

/**
 * An action or query class is not built correctly. A mistake in the code,
 * not in the input.
 */
final class DefinitionException extends LogicException {}
