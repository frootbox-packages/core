<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Ai;

use Frootbox\Core\Action\AsQuery;
use Frootbox\Core\Action\CurrentContext;
use Frootbox\Core\Action\Query;
use Frootbox\Core\Auth\Permissions;

/**
 * @implements Query<AiStatus>
 */
#[AsQuery('ai.status', 'Returns whether the AI is there for the signed-in person and, if not, why: no provider, provider not set up, no permission ai.use or the monthly limit reached', signedIn: true)]
final readonly class ShowAiStatus implements Query
{
    public function handle(Providers $providers, Assistant $assistant, Permissions $permissions, CurrentContext $context): AiStatus
    {
        $provider = $providers->active();
        $model = null === $provider ? null : $providers->client($provider)->model()->title;
        $reason = $assistant->unavailable();

        if (null === $reason && !$permissions->allows($context->get()->actor, 'ai.use')) {
            $reason = AiStatus::NO_PERMISSION;
        }

        return new AiStatus(null === $reason, $reason, $provider?->title, $model);
    }
}
