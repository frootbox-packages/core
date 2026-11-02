<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Site;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Action\Schema\Description;
use Frootbox\Core\Action\Schema\Length;
use Frootbox\Core\Action\Schema\Sensitive;

/**
 * Switches the password on or sets a new one; a new password locks out
 * every browser let in before (decision 0153). Not undoable: the hash is
 * not recorded.
 *
 * @implements Action<ProtectionStatus>
 */
#[AsAction('site.protect', 'Protects the whole website with a password, or sets a new one; visitors then need it, signed-in users and preview links do not', permission: 'protection.manage', undoable: false)]
final readonly class ProtectSite implements Action
{
    public function __construct(
        #[Description('The password visitors enter, at least 8 characters')]
        #[Sensitive]
        #[Length(min: 8, max: 4096)]
        public string $password,
    ) {}

    public function handle(Protections $protections, SiteProtection $protection): ProtectionStatus
    {
        $values = ['password_hash' => $protection->hash($this->password), 'generation' => bin2hex(random_bytes(16))];
        $current = $protections->current();

        if (null === $current) {
            $protections->insert($values);
        } else {
            $protections->update($current->id, $values);
        }

        $protection->clear();

        return $protection->status();
    }
}
