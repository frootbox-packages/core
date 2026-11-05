<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\User;

/**
 * A person signs in with email and password, a service account only with
 * API tokens (decisions 0180, 0285).
 */
enum UserKind: string
{
    case Person = 'person';

    /** No email, no password, no session: only tokens from user.create-token. */
    case Service = 'service';
}
