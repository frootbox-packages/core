<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Auth\Oidc;

use Frootbox\Core\Action\ActionException;
use Frootbox\Core\Support\Message;

/**
 * Why signing in through a provider failed (decision 0322). The messages
 * are for the person on the sign-in page.
 */
final class OidcException extends ActionException
{
    public static function unknownProvider(string $key): self
    {
        return new self(new Message('Signing in with "{provider}" is not available here.', ['provider' => $key]));
    }

    public static function badState(): self
    {
        return new self(new Message('The sign-in took too long or came from another window. Please try again.'));
    }

    public static function failed(string $reason): self
    {
        return new self(new Message('The provider could not sign you in: {reason}', ['reason' => $reason]));
    }

    public static function noAccess(string $title): self
    {
        return new self(new Message('You have no access to this website through {provider}.', ['provider' => $title]));
    }

    public static function blocked(): self
    {
        return new self(new Message('Your account on this website is locked.'));
    }

    public static function ranksHigher(string $title): self
    {
        return new self(new Message('Your account has more rights here than {provider} may give. Sign in with your password.', ['provider' => $title]));
    }

    public static function emailTaken(string $title): self
    {
        return new self(new Message('An account with your email exists on this website already. An admin links it in {provider}, or you link it yourself under My account after signing in with your password.', ['provider' => $title]));
    }

    public static function emailUnverified(string $title): self
    {
        return new self(new Message('{provider} has not confirmed your email yet.', ['provider' => $title]));
    }

    public static function linkedToOther(string $title): self
    {
        return new self(new Message('This account at {provider} is linked to another user already.', ['provider' => $title]));
    }

    public static function alreadyLinked(string $title): self
    {
        return new self(new Message('Your account is linked to {provider} already.', ['provider' => $title]));
    }

    public static function notLinked(): self
    {
        return new self(new Message('Your account is not linked to this provider.'));
    }

    public static function managed(string $title): self
    {
        return new self(new Message('Your account is managed through {provider}; the link is removed there.', ['provider' => $title]));
    }

    public static function serviceAccount(): self
    {
        return new self(new Message('A service account cannot be linked.'));
    }
}
