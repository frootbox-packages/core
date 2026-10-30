<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Action;

/**
 * The way an action or query reached Frootbox.
 */
enum Channel: string
{
    case Web = 'web';
    case Api = 'api';
    case App = 'app';
    case Mcp = 'mcp';
    case Ai = 'ai';
    case Console = 'console';
    case System = 'system';
}
