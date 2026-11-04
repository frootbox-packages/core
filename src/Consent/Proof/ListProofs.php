<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Consent\Proof;

use Frootbox\Core\Action\AsQuery;
use Frootbox\Core\Action\Query;
use Frootbox\Core\Action\Schema\Description;
use Frootbox\Core\Action\Schema\Length;

/**
 * Every kept choice of one consent ID, e.g. the one a visitor reads out
 * of the privacy settings (decision 0264).
 *
 * @implements Query<ProofList>
 */
#[AsQuery('consent.proof.list', 'Lists the kept choices of a consent ID a visitor reads in the privacy settings, the newest first', permission: 'consent.proof.list')]
final readonly class ListProofs implements Query
{
    public function __construct(
        #[Description('The consent ID, e.g. "0f8c…"')]
        #[Length(min: 1, max: 64)]
        public string $consentId,
    ) {}

    public function handle(Proofs $proofs): ProofList
    {
        $proofs->run();

        return new ProofList(array_map(static fn(Proof $proof): array => ['version' => $proof->version, 'choices' => $proof->choices, 'at' => $proof->at], $proofs->of($this->consentId)));
    }
}
