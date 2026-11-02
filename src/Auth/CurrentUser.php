<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Auth;

use Frootbox\Core\Action\CurrentContext;

/**
 * The signed-in user of the current request, for actions about one's own
 * account.
 */
final readonly class CurrentUser
{
    public function __construct(private CurrentContext $context) {}

    /**
     * The user id, or an error if the actor is a guest or the system.
     */
    public function id(): int
    {
        $actor = $this->context->get()->actor;

        return ActorType::User === $actor->type && null !== $actor->id ? $actor->id : throw AuthenticationException::userRequired();
    }

    /**
     * The id of the current session or API token, if any.
     */
    public function tokenId(): ?int
    {
        return $this->context->get()->tokenId;
    }

    public function is(int $userId): bool
    {
        $actor = $this->context->get()->actor;

        return ActorType::User === $actor->type && $actor->id === $userId;
    }
}
