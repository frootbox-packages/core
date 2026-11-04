<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Consent;

use Frootbox\Core\Action\Schema\Description;
use Frootbox\Core\Action\Schema\Length;
use Frootbox\Core\Action\Schema\Range;
use Frootbox\Core\Action\Schema\Title;
use Frootbox\Core\Settings\AsSettings;

/**
 * The settings of the core about the consent of visitors (decision 0264).
 */
#[AsSettings('Privacy', 'How visitors agree to services of other providers')]
final readonly class ConsentSettings
{
    public function __construct(
        #[Title('Consent tool'), Description('Empty: the only consent tool that is set up takes over, the core asks otherwise. The name of a tool to choose one of several, or none for the core.')]
        #[Length(max: 100)]
        public string $tool = '',
        #[Title('Keep a proof of consent'), Description('Keeps each choice of a visitor with a random ID, without address or browser, so you can show it later.')]
        public bool $proof = false,
        #[Title('Keep proofs'), Description('Days a proof of consent is kept.')]
        #[Range(min: 30, max: 3650)]
        public int $proofDays = 1095,
    ) {}
}
