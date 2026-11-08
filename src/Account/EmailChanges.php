<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Account;

use Frootbox\Core\Security\Secrets;
use Frootbox\Core\User\User;
use Frootbox\Core\User\Users;

/**
 * Links to confirm a new email (decision 0341):
 * "<id>.<expiry>.<new email in base64url>.<signature>", signed over all of
 * it and the fingerprint of the old email and the password hash. Nothing
 * is stored; once the email or the password changes, the link is void.
 */
final readonly class EmailChanges
{
    public const int HOURS = 24;

    private const string PURPOSE = 'email-change';

    public function __construct(
        private Users $users,
        private Secrets $secrets,
        private PasswordResets $links,
    ) {}

    /**
     * Whether the website can mail such links, as for a forgotten password.
     */
    public function available(): bool
    {
        return $this->links->available();
    }

    public function token(User $user, string $email, ?int $now = null): string
    {
        $text = $user->id . '.' . (($now ?? time()) + self::HOURS * 3600) . '.' . rtrim(strtr(base64_encode(Users::normalizeEmail($email)), '+/', '-_'), '=');
        $signature = $this->secrets->sign(self::PURPOSE, $text . '.' . $this->links->fingerprint($user)) ?? throw new \LogicException('Links in mails need APP_SECRET.');

        return $text . '.' . $signature;
    }

    public function link(string $token): string
    {
        return $this->links->link($token, 'email');
    }

    /**
     * The person and the new email of a token, null if it is broken,
     * expired or used.
     *
     * @return array{User, string}|null
     */
    public function change(string $token, ?int $now = null): ?array
    {
        if (1 !== preg_match('/^([1-9][0-9]{0,18})\.([0-9]{1,12})\.([A-Za-z0-9_-]{4,400})\.([A-Za-z0-9_-]{20,100})$/D', $token, $match)) {
            return null;
        }

        if ((int) $match[2] < ($now ?? time())) {
            return null;
        }

        $email = base64_decode(strtr($match[3], '-_', '+/'), true);
        $user = $this->users->fetchById((int) $match[1]);

        if (false === $email || null === $user || !$this->links->accepts($user)) {
            return null;
        }

        $text = $match[1] . '.' . $match[2] . '.' . $match[3] . '.' . $this->links->fingerprint($user);

        return $this->secrets->verify(self::PURPOSE, $text, $match[4]) ? [$user, $email] : null;
    }
}
