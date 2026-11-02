<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Site;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Action\Bookkeeping;
use Frootbox\Core\Action\CurrentContext;
use Frootbox\Core\Action\Schema\Length;
use Frootbox\Core\Action\Schema\Sensitive;
use Frootbox\Core\Auth\AuthenticationException;
use Frootbox\Core\Auth\SignInThrottle;

/**
 * Checks the password a visitor entered for the protected website,
 * throttled by address like signing in (decision 0153).
 *
 * @implements Action<SiteAccess>
 */
#[AsAction('site.enter', 'Checks the password of the protected website and lets the visitor in', public: true, undoable: false)]
final readonly class EnterSite implements Action
{
    public function __construct(
        #[Sensitive]
        #[Length(min: 1, max: 4096)]
        public string $password,
    ) {}

    public function handle(SiteProtection $protection, SignInThrottle $throttle, Bookkeeping $bookkeeping, CurrentContext $context): SiteAccess
    {
        $address = $context->get()->address;

        // Before the password, so waiting is not a way to test passwords.
        $throttle->checkSite($address);

        if (!$protection->verify($this->password)) {
            // After the rollback of this action, so the failure counts.
            $bookkeeping->afterwards(fn() => $throttle->failSite($address));

            throw AuthenticationException::wrongPassword();
        }

        return new SiteAccess($protection->grant(), SiteProtection::DAYS);
    }
}
