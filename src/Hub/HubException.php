<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Hub;

use Frootbox\Core\Action\ActionException;
use Frootbox\Core\Support\Message;
use Throwable;

/**
 * Why the website cannot connect to the hub or talk to it (decision 0284).
 */
final class HubException extends ActionException
{
    /** Why, for a hint in the backend, e.g. "secret" or "local". */
    public string $reason = '';

    public static function noSecret(): self
    {
        $error = new self(new Message('Without APP_SECRET in .env the key of the website cannot be stored, so it cannot connect.'));
        $error->reason = 'secret';

        return $error;
    }

    public static function noAddress(): self
    {
        $error = new self(new Message('The address of the website is missing: set "app.url" in config/app.yaml.'));
        $error->reason = 'address';

        return $error;
    }

    public static function local(string $url): self
    {
        $error = new self(new Message('{url} is a local address. Connect the website live or on staging, where the hub can reach it.', ['url' => $url]));
        $error->reason = 'local';

        return $error;
    }

    public static function badHubUrl(string $url): self
    {
        $error = new self(new Message('"hub.url" in config/app.yaml must be an address like https://hub.frootbox.de, not "{url}".', ['url' => $url]));
        $error->reason = 'hub-url';

        return $error;
    }

    public static function connected(): self
    {
        return new self(new Message('The website is connected already. Disconnect it first.'));
    }

    public static function notConnected(): self
    {
        return new self(new Message('The website is not connected.'));
    }

    public static function noRequest(): self
    {
        return new self(new Message('No request waits for this confirmation, or it has expired. Connect again.'));
    }

    public static function keyUnreadable(): self
    {
        return new self(new Message('The key of the website cannot be read, e.g. because APP_SECRET changed. Disconnect and connect again.'));
    }

    public static function unreachable(string $url, ?Throwable $previous = null): self
    {
        return new self(new Message('The hub at {url} cannot be reached. Try again later.', ['url' => $url]), 0, $previous);
    }

    public static function refused(int $status, string $detail): self
    {
        return new self(new Message('The hub refused ({status}): {detail}', ['status' => $status, 'detail' => $detail]));
    }

    public static function badAnswer(string $what): self
    {
        return new self(new Message('The hub answered without {what}.', ['what' => $what]));
    }
}
