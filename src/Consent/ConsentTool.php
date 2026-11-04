<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Consent;

/**
 * A consent tool of another provider that takes over the choice of the
 * visitor (decisions 0263, 0264). Registered by its module:
 *
 *     $registry->add(ConsentTool::class, new ConsentTool(
 *         'acme/consent/tool', 'Acme Consent', AcmeConsent::class,
 *     ));
 *
 * The class is a ConsentToolProvider from the container. Its script
 * calls window.frootboxConsent.use() in the browser.
 */
final readonly class ConsentTool
{
    public function __construct(
        /** After the package, e.g. "acme/consent/tool" (decision 0091) */
        public string $name,
        public string $title,
        /** @var class-string<ConsentToolProvider> */
        public string $provider,
    ) {}
}
