<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Hub;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Action\InvalidInputException;
use Frootbox\Core\Action\Schema\Description;
use Frootbox\Core\Action\Schema\ListOf;

/**
 * Replaces the public keys of the hub (decision 0284). The hub announces
 * a new key on /.well-known/frootbox/keys, signed with a key the website
 * trusts already; the handler runs this action then.
 *
 * @implements Action<HubDetails>
 */
#[AsAction('system.hub-keys', 'Replaces the public keys of the hub the website trusts', undoable: false)]
final readonly class UpdateHubKeys implements Action
{
    /**
     * @param list<string> $keys
     */
    public function __construct(
        #[ListOf('string')]
        #[Description('Public keys "whpk_…" of the hub, at least one')]
        public array $keys,
    ) {}

    public function handle(Hub $hub, HubConnections $connections): HubDetails
    {
        $connection = $hub->connected();
        $keys = array_values(array_unique(array_filter($this->keys, static fn(string $key): bool => 1 === preg_match('~^whpk_[A-Za-z0-9+/]{43}=$~', $key))));

        if ([] === $keys || \count($keys) !== \count($this->keys) || \count($keys) > 10) {
            throw new InvalidInputException('system.hub-keys', ['/keys' => 'must be between 1 and 10 public keys "whpk_…".']);
        }

        $connections->update($connection->id, ['hubKeys' => $keys]);

        return $hub->details();
    }
}
