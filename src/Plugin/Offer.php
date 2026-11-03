<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Plugin;

/**
 * What a plugin offers to the page of a space (decision 0232), e.g. pages
 * of its own for the objects: always, as a switch for the admin, or not.
 */
enum Offer: string
{
    case Required = 'required';
    case Optional = 'optional';
    case None = 'none';

    /**
     * The value for a place: required is on, none is off, optional as chosen.
     */
    public function resolve(?bool $chosen, bool $default = true): bool
    {
        return match ($this) {
            self::Required => true,
            self::None => false,
            self::Optional => $chosen ?? $default,
        };
    }
}
