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
use Frootbox\Core\Auth\AccessTokens;
use Frootbox\Core\Auth\Oidc\OidcPaths;
use Frootbox\Core\Auth\Oidc\RoleLimit;
use Psr\Log\LoggerInterface;

/**
 * The switches of the connection live on the website (decisions 0283,
 * 0284, 0322): "status" and "login". Off takes effect at once, even if
 * the hub cannot be told: the service account and its token are deleted,
 * the sessions through the hub end. On tells the hub first. With "login"
 * the website tells the hub its addresses for signing in and the highest
 * role the hub may give.
 *
 * @implements Action<HubDetails>
 */
#[AsAction('system.hub-switch', 'Turns on or off what the hub may do: read the status, let people sign in', undoable: false)]
final readonly class SwitchHub implements Action
{
    /** The highest role the hub may give unless chosen otherwise (decision 0322). */
    public const string MAX_ROLE = 'admin';

    public function __construct(
        #[Description('Let the hub read the status of the website; unchanged if left out')]
        public ?bool $status = null,
        #[Description('Let people sign in through the hub; unchanged if left out')]
        public ?bool $login = null,
        #[Description('The highest role the hub may give: editor, admin (default) or superuser')]
        public ?string $maxRole = null,
    ) {}

    public function handle(Hub $hub, HubClient $client, HubConnections $connections, HubAccess $access, AccessTokens $tokens, OidcPaths $paths, LoggerInterface $logger): HubDetails
    {
        $connection = $hub->connected();
        $path = '/connect/v1/connections/' . rawurlencode((string) $connection->connectionId);

        if (null !== $this->maxRole && !\in_array($this->maxRole, RoleLimit::STANDARD, true)) {
            throw new InvalidInputException('system.hub-switch', ['/maxRole' => 'must be editor, admin or superuser.']);
        }

        $status = $this->status ?? $connection->status;
        $login = $this->login ?? $connection->login;
        $maxRole = $login ? $this->maxRole ?? $connection->loginMaxRole ?? self::MAX_ROLE : null;
        $statusChanged = $status !== $connection->status;
        $loginChanged = $login !== $connection->login || $maxRole !== $connection->loginMaxRole;

        if (!$statusChanged && !$loginChanged) {
            return $hub->details();
        }

        $body = ['switches' => Hub::switches($status, $login)];
        $changes = [];

        if ($statusChanged && $status) {
            $granted = $access->grant($connection->hubUrl, $hub->siteUrl() ?? throw HubException::noAddress());
            $body['status'] = $granted['status'];
            $changes += ['status' => true, 'userId' => $granted['userId']];
        } elseif ($statusChanged) {
            $access->revoke($connection->userId);
            $body['status'] = null;
            $changes += ['status' => false, 'userId' => null];
        }

        if ($loginChanged && $login) {
            $body['login'] = [
                'redirectUri' => $paths->redirect(HubLogin::PROVIDER) ?? throw HubException::noAddress(),
                'logoutUri' => $paths->logout(HubLogin::PROVIDER),
                'initiateUri' => $paths->initiate(HubLogin::PROVIDER),
                'maxRole' => $maxRole,
            ];
            $changes += ['login' => true, 'loginMaxRole' => $maxRole];
        } elseif ($loginChanged) {
            $body['login'] = null;
            $changes += ['login' => false, 'loginMaxRole' => null];
            // The links stay, so switching on again creates nobody anew.
            $tokens->endProviderSessions(HubLogin::PROVIDER);
        }

        // Turning something on needs the hub; turning off works without.
        $on = ($statusChanged && $status) || ($loginChanged && $login);

        if ($on) {
            $client->send($connection, 'PATCH', $path, $body);
            $connections->update($connection->id, $changes);

            return $hub->details();
        }

        $connections->update($connection->id, $changes);

        try {
            $client->send($connection, 'PATCH', $path, $body);
        } catch (HubException $error) {
            // The token works no more; the hub notices with the next 401.
            $logger->warning('The hub was not told about the switches: {message}', ['message' => $error->getMessage()]);
        }

        return $hub->details();
    }
}
