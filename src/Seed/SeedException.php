<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Seed;

use RuntimeException;

/**
 * A seed file that cannot be read or a step that failed.
 */
final class SeedException extends RuntimeException {}
