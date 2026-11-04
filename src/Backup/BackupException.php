<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Backup;

use RuntimeException;

/**
 * A backup could not be made, read or restored.
 */
final class BackupException extends RuntimeException {}
