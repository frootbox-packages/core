<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Consent;

/**
 * The services of other providers on the website and the consent tool
 * that takes over, if any (decision 0264).
 */
final readonly class ServiceList
{
    /**
     * @param list<array{name: string, title: string, provider: string, privacyUrl: string, purpose: string, reach: string}> $services
     */
    public function __construct(
        public array $services,
        /** The name of the active consent tool, null when the core asks */
        public ?string $tool,
    ) {}
}
