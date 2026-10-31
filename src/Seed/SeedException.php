<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Seed;

use RuntimeException;

/**
 * A seed file that cannot be read or a step that failed.
 */
final class SeedException extends RuntimeException {}
