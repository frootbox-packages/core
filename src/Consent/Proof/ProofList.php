<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Consent\Proof;

/**
 * The kept choices of one consent ID.
 */
final readonly class ProofList
{
    /**
     * @param list<array{version: string, choices: array<string, bool>, at: string}> $proofs the newest first
     */
    public function __construct(public array $proofs) {}
}
