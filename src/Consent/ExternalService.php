<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Consent;

/**
 * A service of another provider whose content loads only after the
 * visitor agrees, e.g. videos of YouTube (decision 0224). Registered by
 * the extension that uses it:
 *
 *     $registry->add(ExternalService::class, new ExternalService(
 *         'acme/maps/openstreetmap', 'OpenStreetMap', 'OpenStreetMap Foundation',
 *         'https://osmfoundation.org/wiki/Privacy_Policy',
 *     ));
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
    ) {}
}
