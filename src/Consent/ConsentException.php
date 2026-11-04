<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Consent;

use LogicException;

/**
 * A mistake of an extension in using consent, e.g. a script on every page
 * for a service that is not registered.
 */
final class ConsentException extends LogicException {}
