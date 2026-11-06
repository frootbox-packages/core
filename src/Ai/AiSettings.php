<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Ai;

use Frootbox\Core\Action\Schema\Description;
use Frootbox\Core\Action\Schema\Length;
use Frootbox\Core\Action\Schema\Range;
use Frootbox\Core\Action\Schema\Title;
use Frootbox\Core\Settings\AsSettings;

/**
 * The AI of the website (decision 0294), "frootbox/core.ai". The key and
 * the model belong to the settings of the provider.
 */
#[AsSettings('AI', 'Which provider answers and how much the AI may cost a month. Only the text of each task goes to the provider, never the whole website.', section: 'ai')]
final readonly class AiSettings
{
    public function __construct(
        #[Title('Provider'), Description('The package of the provider, e.g. frootbox/ai-anthropic. Empty: the first one installed.')]
        #[Length(max: 100)]
        public string $provider = '',
        #[Title('Monthly limit in US dollars'), Description('When the costs of the month reach it, the AI stops until the next month; the website goes on without it. 0: no limit.')]
        #[Range(min: 0, max: 100000)]
        public float $monthlyLimit = 0.0,
    ) {}

    /**
     * The limit in millionths of a dollar, null for none.
     */
    public function limit(): ?int
    {
        return $this->monthlyLimit > 0 ? (int) round($this->monthlyLimit * 1_000_000) : null;
    }
}
