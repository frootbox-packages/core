<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Help;

use RuntimeException;

/**
 * A help file that cannot be read, e.g. without a title.
 */
final class HelpException extends RuntimeException {}
