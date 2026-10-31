<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Action;

use Frootbox\Core\Auth\Actor;
use Frootbox\Core\Auth\ActorType;
use Frootbox\Core\Support\Message;

/**
 * The actor may not do this. For a guest, signing in may help, which the
 * REST API answers with 401 instead of 403.
 */
final class AccessDeniedException extends ActionException
{
    public function __construct(string|Message $message, public readonly bool $signInRequired = false)
    {
        parent::__construct($message);
    }

    public static function for(Descriptor $descriptor, Actor $actor): self
    {
        return new self(
            new Message('{actor} may not run "{action}" (permission "{permission}").', ['actor' => $actor->name, 'action' => $descriptor->name, 'permission' => $descriptor->permission]),
            ActorType::Guest === $actor->type,
        );
    }

    public static function forPermission(string $permission, Actor $actor): self
    {
        return new self(new Message('{actor} lacks the permission "{permission}".', ['actor' => $actor->name, 'permission' => $permission]), ActorType::Guest === $actor->type);
    }
}
