<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Hub;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Action\Schema\Description;
use Psr\Log\LoggerInterface;

/**
 * Disconnects from the hub (decision 0284): deletes service account,
 * token and the keys of the hub at once, whether or not the hub can be
 * told. The key pair of the website stays for the next connection unless
 * forgetKey asks otherwise, e.g. in a copy of the website.
 *
 * @implements Action<HubDetails>
 */
#[AsAction('system.hub-disconnect', 'Disconnects the website from the hub and deletes its access at once', undoable: false)]
final readonly class DisconnectHub implements Action
{
    public function __construct(
        #[Description('Tell the hub; false when the hub itself disconnected')]
        public bool $notify = true,
        #[Description('Also forget the key pair of the website, e.g. in a copy of it')]
        public bool $forgetKey = false,
    ) {}

    public function handle(Hub $hub, HubClient $client, HubConnections $connections, HubAccess $access, LoggerInterface $logger): HubDetails
    {
        $connection = $hub->current();

        if (null === $connection || (!$connection->connected() && null === $connection->requestId && !$this->forgetKey)) {
            return $this->forgetKey ? $hub->details() : throw HubException::notConnected();
        }

        $access->revoke($connection->userId);

        if ($this->forgetKey) {
            $connections->delete($connection->id);
        } else {
            $connections->update($connection->id, [
                'hubKeys' => [],
                'requestId' => null,
                'requestExpiresAt' => null,
                'connectionId' => null,
                'organization' => null,
                'status' => false,
                'packages' => false,
                'login' => false,
                'userId' => null,
                'connectedAt' => null,
            ]);
        }

        if ($this->notify && $connection->connected()) {
            try {
                $client->send($connection, 'DELETE', '/connect/v1/connections/' . rawurlencode((string) $connection->connectionId));
            } catch (HubException $error) {
                // The token works no more; the hub notices with the next 401.
                $logger->warning('The hub was not told about the disconnection: {message}', ['message' => $error->getMessage()]);
            }
        }

        return $hub->details();
    }
}
