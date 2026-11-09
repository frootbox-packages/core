<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Page;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Action\Bookkeeping;
use Frootbox\Core\Action\CurrentContext;
use Frootbox\Core\Action\Schema\Length;
use Frootbox\Core\Action\Schema\Pattern;
use Frootbox\Core\Action\Schema\Sensitive;
use Frootbox\Core\Auth\AuthenticationException;
use Frootbox\Core\Auth\SignInThrottle;
use Frootbox\Database\Ulid;

/**
 * Checks the password a visitor entered for a protected page, throttled
 * by address like the password of the website, so guessing at a page
 * counts like guessing at the website (decision 0358).
 *
 * @implements Action<PageAccess>
 */
#[AsAction('page.enter', 'Checks the password of a protected page and lets the visitor in', public: true, undoable: false)]
final readonly class EnterPage implements Action
{
    public function __construct(
        #[Pattern(Ulid::PATTERN)]
        public string $id,
        #[Sensitive]
        #[Length(min: 1, max: 4096)]
        public string $password,
    ) {}

    public function handle(PagePasswords $passwords, SignInThrottle $throttle, Bookkeeping $bookkeeping, CurrentContext $context): PageAccess
    {
        $address = $context->get()->address;
        // Before the password, so waiting is not a way to test passwords.
        $throttle->checkSite($address);

        if (!$passwords->verify($this->id, $this->password)) {
            // After the rollback of this action, so the failure counts.
            $bookkeeping->afterwards(fn() => $throttle->failSite($address));

            throw AuthenticationException::wrongPassword();
        }

        return new PageAccess(PagePasswords::cookie($this->id), $passwords->grant($this->id), PagePasswords::DAYS);
    }
}
