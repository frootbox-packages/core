<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Preview;

enum PreviewStatus: string
{
    case Valid = 'valid';
    case Expired = 'expired';
    case Revoked = 'revoked';
}
