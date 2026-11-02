<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Security;

/**
 * A secret cannot be encrypted, because APP_SECRET is missing.
 */
final class SecretsException extends \RuntimeException {}
