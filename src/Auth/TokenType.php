<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Auth;

enum TokenType: string
{
    /** A browser session behind a cookie, extended while it is used. */
    case Session = 'session';

    /** A token for the REST API, the app or an MCP client. */
    case Api = 'api';
}
