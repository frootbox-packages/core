<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Action;

use Frootbox\Core\Auth\Actor;

/**
 * Who runs an action and through which channel. A signed-in user also
 * brings the access token of the session or API client. A request from
 * the web brings the address of the client, e.g. for throttling the
 * sign-in (decision 0115). A guest with a valid preview link brings its
 * id, for actions with preview: true (decision 0156).
 */
final readonly class Context
{
    public function __construct(
        public Actor $actor,
        public Channel $channel,
        public ?int $tokenId = null,
        public ?string $address = null,
        public ?string $previewLinkId = null,
    ) {}

    public static function system(Channel $channel = Channel::System): self
    {
        return new self(Actor::system(), $channel);
    }

    public static function guest(Channel $channel = Channel::Web, ?string $address = null, ?string $previewLinkId = null): self
    {
        return new self(Actor::guest(), $channel, address: $address, previewLinkId: $previewLinkId);
    }

    /**
     * The same guest with a valid preview link (decision 0156).
     */
    public function withPreview(string $linkId): self
    {
        return new self($this->actor, $this->channel, $this->tokenId, $this->address, $linkId);
    }
}
