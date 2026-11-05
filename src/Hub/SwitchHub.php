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
 * The switches of the connection live on the website (decisions 0283,
 * 0284); for now only "status". Off deletes the service account and its
 * token at once, even if the hub cannot be told.
 *
 * @implements Action<HubDetails>
 */
#[AsAction('system.hub-switch', 'Turns on or off what the hub may do; for now reading the status', undoable: false)]
final readonly class SwitchHub implements Action
{
    public function __construct(
        #[Description('Let the hub read the status of the website')]
        public bool $status,
    ) {}

    public function handle(Hub $hub, HubClient $client, HubConnections $connections, HubAccess $access, LoggerInterface $logger): HubDetails
    {
        $connection = $hub->connected();
        $path = '/connect/v1/connections/' . rawurlencode((string) $connection->connectionId);

        if ($connection->status === $this->status) {
            return $hub->details();
        }

        if ($this->status) {
            $granted = $access->grant($connection->hubUrl, $hub->siteUrl() ?? throw HubException::noAddress());
            $client->send($connection, 'PATCH', $path, ['switches' => Hub::switches(true), 'status' => $granted['status']]);
            $connections->update($connection->id, ['status' => true, 'userId' => $granted['userId']]);

            return $hub->details();
        }

        $access->revoke($connection->userId);
        $connections->update($connection->id, ['status' => false, 'userId' => null]);

        try {
            $client->send($connection, 'PATCH', $path, ['switches' => Hub::switches(false), 'status' => null]);
        } catch (HubException $error) {
            // The token works no more; the hub notices with the next 401.
            $logger->warning('The hub was not told that the status is off: {message}', ['message' => $error->getMessage()]);
        }

        return $hub->details();
    }
}
