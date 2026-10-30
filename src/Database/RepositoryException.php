<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Database;

use RuntimeException;

/**
 * Wrong use of a repository, e.g. an unknown option or a bad column name.
 */
final class RepositoryException extends RuntimeException {}
