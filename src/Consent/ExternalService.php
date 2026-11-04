<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Consent;

/**
 * A service of another provider whose content loads only after the
 * visitor agrees, e.g. videos of YouTube (decisions 0224, 0264).
 * Registered by the extension that uses it:
 *
 *     $registry->add(ExternalService::class, new ExternalService(
 *         'acme/maps/openstreetmap', 'OpenStreetMap', 'OpenStreetMap Foundation',
 *         'https://osmfoundation.org/wiki/Privacy_Policy',
 *     ));
 *
 * A service on every page, e.g. statistics, has the reach "site" and puts
 * its script into the head with ConsentMarkup::deferred(); then the
 * banner asks for it.
 */
final readonly class ExternalService
{
    public function __construct(
        /** After the package, e.g. "acme/video/youtube" (decision 0091) */
        public string $name,
        /** The name visitors know, e.g. "YouTube" */
        public string $title,
        /** Who gets the data, e.g. "Google Ireland Limited" */
        public string $provider,
        /** The privacy policy of the provider */
        public string $privacyUrl,
        public Purpose $purpose = Purpose::Media,
        public Reach $reach = Reach::Content,
    ) {}
}
