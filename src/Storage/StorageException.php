<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Storage;

use RuntimeException;

/**
 * A store could not read or write, or its DSN is wrong (decision 0268).
 */
final class StorageException extends RuntimeException {}
