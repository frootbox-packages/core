<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Hub;

use DateTimeImmutable;
use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Action\Schema\Description;
use Frootbox\Core\Action\Schema\Pattern;
use Frootbox\Core\Action\Schema\Sensitive;

/**
 * Connects with a one-time code from the hub (decision 0284), signed
 * with the key of the website, so the code is worth nothing without it:
 *
 * - with request: the code the browser brings back after the request of
 *   system.hub-connect was confirmed on the hub;
 * - without: a code made in the account area of the hub, e.g. for
 *   automatic deployment (bin/frootbox hub:connect <code>).
 *
 * With the switch "status" it creates a service account that may only
 * ask status.show, and hands the hub its token.
 *
 * @implements Action<HubDetails>
 */
#[AsAction('system.hub-complete', 'Connects the website to the hub with a one-time code from it', undoable: false)]
final readonly class CompleteHub implements Action
{
    public function __construct(
        #[Sensitive]
        #[Pattern('^[A-Za-z0-9_-]{6,100}$')]
        #[Description('The one-time code from the hub')]
        public string $code,
        #[Pattern('^' . Hub::ID . '$')]
        #[Description('The request the code confirms; without it the code alone connects')]
        public ?string $request = null,
        #[Description('Without request: let the hub read the status of the website')]
        public bool $status = true,
    ) {}

    public function handle(Hub $hub, HubClient $client, HubConnections $connections, HubAccess $access): HubDetails
    {
        ['site' => $site, 'hub' => $hubUrl] = $hub->check();

        if (null === $this->request) {
            $connection = $hub->identity($hubUrl);
            $status = $this->status;
        } else {
            $connection = $hub->current();

            if (null !== $connection && $connection->connected()) {
                throw HubException::connected();
            }

            if (null === $connection || !$connection->pending() || !hash_equals((string) $connection->requestId, $this->request)) {
                throw HubException::noRequest();
            }

            $status = $connection->status;
        }

        $granted = $status ? $access->grant($connection->hubUrl, $site) : null;

        $answer = null === $this->request
            ? $client->send($connection, 'POST', '/connect/v1/codes/' . rawurlencode($this->code), [...$hub->describe($connection, $site, $status), 'status' => $granted['status'] ?? null])
            : $client->send($connection, 'POST', '/connect/v1/requests/' . rawurlencode($this->request) . '/complete', ['code' => $this->code, 'status' => $granted['status'] ?? null]);

        $id = $answer['connection'] ?? null;
        $organization = $answer['organization'] ?? null;

        if (!\is_string($id) || 1 !== preg_match('~^' . Hub::ID . '$~', $id)) {
            throw HubException::badAnswer('connection');
        }

        $connections->update($connection->id, [
            'requestId' => null,
            'requestExpiresAt' => null,
            'connectionId' => $id,
            'organization' => \is_string($organization) ? mb_substr($organization, 0, 255) : null,
            'status' => $status,
            'packages' => false,
            'login' => false,
            'userId' => $granted['userId'] ?? null,
            'connectedAt' => new DateTimeImmutable(),
        ]);

        return $hub->details();
    }
}
