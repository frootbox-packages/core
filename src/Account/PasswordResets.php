<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Account;

use Frootbox\Core\Config\Config;
use Frootbox\Core\Mail\Mailer;
use Frootbox\Core\Security\Secrets;
use Frootbox\Core\User\User;
use Frootbox\Core\User\UserKind;
use Frootbox\Core\User\Users;

/**
 * Links for a forgotten password (decision 0326) and invitations (decision
 * 0342): "<id>.<expiry>.<signature>", signed over the id, the expiry and a
 * fingerprint of the email and the current password hash. Nothing is
 * stored; once the password changes, every older link is void.
 */
final readonly class PasswordResets
{
    public const int MINUTES = 60;

    /** How long an invitation works (decision 0342). */
    public const int INVITATION_DAYS = 7;

    private const string PURPOSE = 'password-reset';

    private const string INVITATION = 'invitation';

    public function __construct(
        private Users $users,
        private Secrets $secrets,
        private Config $config,
        private Mailer $mailer,
    ) {}

    /**
     * Whether the website can send such links: a secret to sign them, its
     * own address from app.url and a way for mails.
     */
    public function available(): bool
    {
        return $this->secrets->available() && null !== $this->siteUrl() && 'null' !== $this->mailer->status()->transport;
    }

    /**
     * Whether a person can get a link: active, with an email, no service account.
     */
    public function accepts(User $user): bool
    {
        return $user->active && null !== $user->email && UserKind::Service !== $user->kind;
    }

    public function token(User $user, ?int $now = null): string
    {
        return $this->sign(self::PURPOSE, $user, self::MINUTES * 60, $now);
    }

    /**
     * The person a token belongs to, null if it is broken, expired, used
     * or the person may not get one any more.
     */
    public function user(string $token, ?int $now = null): ?User
    {
        return $this->check(self::PURPOSE, $token, $now);
    }

    /**
     * A link of an invitation: the person sets a password (decision 0342).
     */
    public function invitationToken(User $user, ?int $now = null): string
    {
        return $this->sign(self::INVITATION, $user, self::INVITATION_DAYS * 86400, $now);
    }

    /**
     * The person an invitation belongs to, null once it is broken,
     * expired or a password is set.
     */
    public function invited(string $token, ?int $now = null): ?User
    {
        $user = $this->check(self::INVITATION, $token, $now);

        return null === $user || null !== $this->users->passwordHash($user->id) ? null : $user;
    }

    /**
     * The address of a page for a token below backend.path, by default
     * the one for a new password.
     */
    public function link(string $token, string $page = 'password'): string
    {
        $site = $this->siteUrl() ?? throw new \LogicException('Links in mails need app.url.');

        return $site . '/' . trim($this->config->string('backend.path', '/cms'), '/') . '/' . $page . '/' . $token;
    }

    /**
     * The address of the website from app.url, never from a request: a
     * forged Host must not put another site into the mail.
     */
    public function siteUrl(): ?string
    {
        $url = $this->config->get('app.url');

        return \is_string($url) && 1 === preg_match('#^https?://[^/?\#]+(/[^?\#]*)?$#', $url) ? rtrim($url, '/') : null;
    }

    /**
     * Changes with the email and with the password, so a used link is void.
     */
    public function fingerprint(User $user): string
    {
        return hash('sha256', ($user->email ?? '') . "\0" . ($this->users->passwordHash($user->id) ?? ''));
    }

    private function sign(string $purpose, User $user, int $seconds, ?int $now): string
    {
        $text = $user->id . '.' . (($now ?? time()) + $seconds);
        $signature = $this->secrets->sign($purpose, $text . '.' . $this->fingerprint($user)) ?? throw new \LogicException('Links in mails need APP_SECRET.');

        return $text . '.' . $signature;
    }

    private function check(string $purpose, string $token, ?int $now): ?User
    {
        if (1 !== preg_match('/^([1-9][0-9]{0,18})\.([0-9]{1,12})\.([A-Za-z0-9_-]{20,100})$/D', $token, $match)) {
            return null;
        }

        if ((int) $match[2] < ($now ?? time())) {
            return null;
        }

        $user = $this->users->fetchById((int) $match[1]);

        if (null === $user || !$this->accepts($user)) {
            return null;
        }

        return $this->secrets->verify($purpose, $match[1] . '.' . $match[2] . '.' . $this->fingerprint($user), $match[3]) ? $user : null;
    }
}
