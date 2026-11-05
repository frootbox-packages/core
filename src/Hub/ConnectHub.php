<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Hub;

use DateTimeImmutable;
use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Action\InvalidInputException;
use Frootbox\Core\Action\Schema\Description;
use Frootbox\Core\Action\Schema\Length;
use Frootbox\Core\Support\Message;

/**
 * Opens a request to connect (decision 0284): makes the key pair of the
 * website if it has none, reads the keys of the hub and tells it the
 * website. The browser then goes to the url of the answer; the hub calls
 * back on /.well-known/frootbox/verify and returns with a code for
 * system.hub-complete.
 *
 * @implements Action<HubRequest>
 */
#[AsAction('system.hub-connect', 'Opens a request to connect the website to the hub, to confirm there', undoable: false)]
final readonly class ConnectHub implements Action
{
    public function __construct(
        #[Description('Let the hub read the status of the website')]
        public bool $status = true,
        #[Length(max: 2000)]
        #[Description('Where the hub sends the browser back with the code, an address of this website, e.g. the screen of the backend')]
        public ?string $return = null,
    ) {}

    public function handle(Hub $hub, HubClient $client, HubConnections $connections): HubRequest
    {
        ['site' => $site, 'hub' => $hubUrl] = $hub->check();

        if (null !== $this->return && (!str_starts_with($this->return, $site . '/') || 1 === preg_match('~[\s\x00-\x1f]~', $this->return))) {
            throw new InvalidInputException('system.hub-connect', ['/return' => new Message('must be an address of this website, {site}/….', ['site' => $site])]);
        }

        $connection = $hub->identity($hubUrl);
        $answer = $client->send($connection, 'POST', '/connect/v1/requests', [...$hub->describe($connection, $site, $this->status), 'return' => $this->return]);
        $id = $answer['id'] ?? null;

        if (!\is_string($id) || 1 !== preg_match('~^' . Hub::ID . '$~', $id)) {
            throw HubException::badAnswer('id');
        }

        $expires = new DateTimeImmutable('+' . Hub::REQUEST_MINUTES . ' minutes');
        $connections->update($connection->id, ['requestId' => $id, 'requestExpiresAt' => $expires, 'status' => $this->status]);

        // Built here, not taken from the answer: the browser only ever goes to the hub.
        return new HubRequest($id, $hubUrl . '/connect/' . $id, $expires->format(\DATE_ATOM));
    }
}
