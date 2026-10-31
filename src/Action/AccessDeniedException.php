<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Action;

use Frootbox\Core\Auth\Actor;
use Frootbox\Core\Auth\ActorType;

/**
 * The actor may not do this. For a guest, signing in may help, which the
 * REST API answers with 401 instead of 403.
 */
final class AccessDeniedException extends ActionException
{
    public function __construct(string $message, public readonly bool $signInRequired = false)
    {
        parent::__construct($message);
    }

    public static function for(Descriptor $descriptor, Actor $actor): self
    {
        return new self(
            \sprintf('%s may not run "%s" (permission "%s").', $actor->name, $descriptor->name, $descriptor->permission),
            ActorType::Guest === $actor->type,
        );
    }

    public static function forPermission(string $permission, Actor $actor): self
    {
        return new self(\sprintf('%s lacks the permission "%s".', $actor->name, $permission), ActorType::Guest === $actor->type);
    }
}
