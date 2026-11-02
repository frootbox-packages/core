<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Site;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Action\ConflictException;

/**
 * Switches the password of the website off. Where SITE_PROTECT in .env
 * forces it, this is refused (decision 0154).
 *
 * @implements Action<ProtectionStatus>
 */
#[AsAction('site.unprotect', 'Switches the password of the website off, so everyone sees it again', permission: 'protection.manage', undoable: false)]
final readonly class UnprotectSite implements Action
{
    public function handle(Protections $protections, SiteProtection $protection): ProtectionStatus
    {
        if ($protection->forced()) {
            throw new ConflictException('SITE_PROTECT in .env keeps the website protected here. Remove it there to switch the protection off.');
        }

        $current = $protections->current();

        if (null !== $current) {
            $protections->delete($current->id);
        }

        $protection->clear();

        return $protection->status();
    }
}
