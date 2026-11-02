<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Account;

/**
 * The languages of the backend for the signed-in user (decision 0161).
 */
final readonly class AccountLanguages
{
    /**
     * @param list<array{code: string, name: string}> $languages English and every language with texts
     * @param string|null                             $chosen    Null for automatic
     * @param string                                  $standard  The language without a choice
     * @param string                                  $current   The language in use
     */
    public function __construct(
        public array $languages,
        public ?string $chosen,
        public string $standard,
        public string $current,
    ) {}
}
