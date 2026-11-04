<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Consent;

use Frootbox\Core\Action\AsQuery;
use Frootbox\Core\Action\Query;

/**
 * Every service of another provider that may load on the website, for a
 * consent tool or the privacy policy (decision 0264). Public: visitors
 * see the same in the privacy settings.
 *
 * @implements Query<ServiceList>
 */
#[AsQuery('consent.services', 'Lists the services of other providers that load on the website after consent, with purpose and reach, and the consent tool that takes over', public: true)]
final readonly class ListServices implements Query
{
    public function handle(ExternalServices $services, ConsentTools $tools): ServiceList
    {
        return new ServiceList(
            array_values(array_map(static fn(ExternalService $service): array => [
                'name' => $service->name,
                'title' => $service->title,
                'provider' => $service->provider,
                'privacyUrl' => $service->privacyUrl,
                'purpose' => $service->purpose->value,
                'reach' => $service->reach->value,
            ], $services->all())),
            $tools->active()?->name,
        );
    }
}
